<?php

namespace Tests\Unit\Services;

use App\Models\Archive;
use App\Models\Channel;
use App\Models\Song;
use App\Models\TsItem;
use App\Services\ChangeListService;
use App\Services\ChannelQueryService;
use App\Services\CoverSongTitleExtractorService;
use App\Services\RefreshArchiveService;
use App\Services\SubtitleFingerprintService;
use App\Services\VideoAnalyzerService;
use App\Services\YouTubeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class RefreshArchiveServiceSongIdTest extends TestCase
{
    use RefreshDatabase;

    protected RefreshArchiveService $service;

    protected $youtubeService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->youtubeService = Mockery::mock(YouTubeService::class);

        $this->service = new RefreshArchiveService(
            $this->youtubeService,
            app(ChangeListService::class),
            app(ChannelQueryService::class),
            app(VideoAnalyzerService::class),
            app(CoverSongTitleExtractorService::class),
            app(SubtitleFingerprintService::class)
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * refreshArchives後にts_items.song_idが復元されることを確認
     */
    public function test_song_id_preserved_after_refresh(): void
    {
        $channel = Channel::factory()->create(['channel_id' => 'UC123456789']);

        // 楽曲マスタを作成
        $song = Song::factory()->create(['title' => 'Test Song']);

        // 既存のアーカイブとタイムスタンプを作成
        Archive::create([
            'id' => Str::ulid(),
            'video_id' => 'video123abc',
            'channel_id' => $channel->channel_id,
            'title' => '歌枠アーカイブ',
            'is_public' => true,
            'is_display' => true,
            'published_at' => now(),
            'comments_updated_at' => now(),
        ]);

        TsItem::create([
            'id' => Str::ulid(),
            'video_id' => 'video123abc',
            'comment_id' => 'video123abc',
            'type' => '1',
            'ts_text' => '1:00',
            'ts_num' => 60,
            'text' => 'Test Song',
            'is_display' => true,
            'song_id' => $song->id,
        ]);

        // YouTubeServiceが同じデータを返すようモック設定
        $this->youtubeService
            ->shouldReceive('getArchivesAndTsItems')
            ->once()
            ->andReturn([
                [
                    'id' => Str::uuid()->toString(),
                    'video_id' => 'video123abc',
                    'channel_id' => $channel->channel_id,
                    'title' => '歌枠アーカイブ',
                    'thumbnail' => '',
                    'is_public' => true,
                    'is_display' => true,
                    'published_at' => now(),
                    'comments_updated_at' => now(),
                    'description' => '',
                    'ts_items' => [
                        [
                            'id' => Str::uuid()->toString(),
                            'video_id' => 'video123abc',
                            'comment_id' => 'video123abc',
                            'type' => '1',
                            'ts_text' => '1:00',
                            'ts_num' => 60,
                            'text' => 'Test Song',
                            'is_display' => true,
                        ],
                    ],
                ],
            ]);

        $this->service->refreshArchives($channel);

        // song_idが復元されていることを確認
        $tsItem = TsItem::where('video_id', 'video123abc')
            ->where('ts_text', '1:00')
            ->first();

        $this->assertNotNull($tsItem);
        $this->assertEquals($song->id, $tsItem->song_id);
    }

    /**
     * APIレスポンスから除外された動画のsong_idは復元されないことを確認
     */
    public function test_song_id_not_restored_for_removed_video(): void
    {
        $channel = Channel::factory()->create(['channel_id' => 'UC123456789']);

        $song = Song::factory()->create(['title' => 'Test Song']);

        // 既存のアーカイブとタイムスタンプを作成
        Archive::create([
            'id' => Str::ulid(),
            'video_id' => 'removed_vid',
            'channel_id' => $channel->channel_id,
            'title' => '消える配信',
            'is_public' => true,
            'is_display' => true,
            'published_at' => now(),
            'comments_updated_at' => now(),
        ]);

        TsItem::create([
            'id' => Str::ulid(),
            'video_id' => 'removed_vid',
            'comment_id' => 'removed_vid',
            'type' => '1',
            'ts_text' => '1:00',
            'ts_num' => 60,
            'text' => 'Test Song',
            'is_display' => true,
            'song_id' => $song->id,
        ]);

        // APIレスポンスにその動画は含まれない（動画削除/非公開化）
        $this->youtubeService
            ->shouldReceive('getArchivesAndTsItems')
            ->once()
            ->andReturn([]);

        $this->service->refreshArchives($channel);

        // 動画もts_itemsも消えていることを確認
        $this->assertNull(Archive::where('video_id', 'removed_vid')->first());
        $this->assertCount(0, TsItem::where('video_id', 'removed_vid')->get());
    }

    /**
     * ts_textが変わった場合にsong_idが復元されないことを確認
     */
    public function test_song_id_not_restored_when_key_changes(): void
    {
        $channel = Channel::factory()->create(['channel_id' => 'UC123456789']);

        $song = Song::factory()->create(['title' => 'Test Song']);

        Archive::create([
            'id' => Str::ulid(),
            'video_id' => 'video123abc',
            'channel_id' => $channel->channel_id,
            'title' => '歌枠アーカイブ',
            'is_public' => true,
            'is_display' => true,
            'published_at' => now(),
            'comments_updated_at' => now(),
        ]);

        TsItem::create([
            'id' => Str::ulid(),
            'video_id' => 'video123abc',
            'comment_id' => 'video123abc',
            'type' => '1',
            'ts_text' => '1:00',
            'ts_num' => 60,
            'text' => 'Test Song',
            'is_display' => true,
            'song_id' => $song->id,
        ]);

        // APIレスポンスではts_textが変更されている
        $this->youtubeService
            ->shouldReceive('getArchivesAndTsItems')
            ->once()
            ->andReturn([
                [
                    'id' => Str::uuid()->toString(),
                    'video_id' => 'video123abc',
                    'channel_id' => $channel->channel_id,
                    'title' => '歌枠アーカイブ',
                    'thumbnail' => '',
                    'is_public' => true,
                    'is_display' => true,
                    'published_at' => now(),
                    'comments_updated_at' => now(),
                    'description' => '',
                    'ts_items' => [
                        [
                            'id' => Str::uuid()->toString(),
                            'video_id' => 'video123abc',
                            'comment_id' => 'video123abc',
                            'type' => '1',
                            'ts_text' => '1:05',  // ts_textが変更
                            'ts_num' => 65,        // ts_numも変更
                            'text' => 'Test Song',
                            'is_display' => true,
                        ],
                    ],
                ],
            ]);

        $this->service->refreshArchives($channel);

        // ts_textが変わったため、song_idは復元されない
        $tsItem = TsItem::where('video_id', 'video123abc')->first();
        $this->assertNotNull($tsItem);
        $this->assertNull($tsItem->song_id);
    }

    /**
     * 同じsong_idを持つ複数のts_itemsが一括復元されることを確認（#813）
     */
    public function test_multiple_items_with_same_song_id_restored_in_batch(): void
    {
        $channel = Channel::factory()->create(['channel_id' => 'UC123456789']);

        $songA = Song::factory()->create(['title' => 'Song A']);
        $songB = Song::factory()->create(['title' => 'Song B']);

        Archive::create([
            'id' => Str::ulid(),
            'video_id' => 'video123abc',
            'channel_id' => $channel->channel_id,
            'title' => '歌枠アーカイブ',
            'is_public' => true,
            'is_display' => true,
            'published_at' => now(),
            'comments_updated_at' => now(),
        ]);

        // songA に2つ、songB に1つマッピング
        TsItem::create([
            'id' => Str::ulid(),
            'video_id' => 'video123abc',
            'comment_id' => 'video123abc',
            'type' => '1',
            'ts_text' => '1:00',
            'ts_num' => 60,
            'text' => 'Song A (1st)',
            'is_display' => true,
            'song_id' => $songA->id,
        ]);
        TsItem::create([
            'id' => Str::ulid(),
            'video_id' => 'video123abc',
            'comment_id' => 'video123abc',
            'type' => '1',
            'ts_text' => '5:00',
            'ts_num' => 300,
            'text' => 'Song A (2nd)',
            'is_display' => true,
            'song_id' => $songA->id,
        ]);
        TsItem::create([
            'id' => Str::ulid(),
            'video_id' => 'video123abc',
            'comment_id' => 'video123abc',
            'type' => '1',
            'ts_text' => '10:00',
            'ts_num' => 600,
            'text' => 'Song B',
            'is_display' => true,
            'song_id' => $songB->id,
        ]);

        $tsItems = [
            [
                'id' => Str::uuid()->toString(),
                'video_id' => 'video123abc',
                'comment_id' => 'video123abc',
                'type' => '1',
                'ts_text' => '1:00',
                'ts_num' => 60,
                'text' => 'Song A (1st)',
                'is_display' => true,
            ],
            [
                'id' => Str::uuid()->toString(),
                'video_id' => 'video123abc',
                'comment_id' => 'video123abc',
                'type' => '1',
                'ts_text' => '5:00',
                'ts_num' => 300,
                'text' => 'Song A (2nd)',
                'is_display' => true,
            ],
            [
                'id' => Str::uuid()->toString(),
                'video_id' => 'video123abc',
                'comment_id' => 'video123abc',
                'type' => '1',
                'ts_text' => '10:00',
                'ts_num' => 600,
                'text' => 'Song B',
                'is_display' => true,
            ],
        ];

        $this->youtubeService
            ->shouldReceive('getArchivesAndTsItems')
            ->once()
            ->andReturn([
                [
                    'id' => Str::uuid()->toString(),
                    'video_id' => 'video123abc',
                    'channel_id' => $channel->channel_id,
                    'title' => '歌枠アーカイブ',
                    'thumbnail' => '',
                    'is_public' => true,
                    'is_display' => true,
                    'published_at' => now(),
                    'comments_updated_at' => now(),
                    'description' => '',
                    'ts_items' => $tsItems,
                ],
            ]);

        $this->service->refreshArchives($channel);

        // songAの2つのts_itemsが両方とも復元されていること
        $item1 = TsItem::where('video_id', 'video123abc')->where('ts_text', '1:00')->first();
        $item2 = TsItem::where('video_id', 'video123abc')->where('ts_text', '5:00')->first();
        $item3 = TsItem::where('video_id', 'video123abc')->where('ts_text', '10:00')->first();

        $this->assertEquals($songA->id, $item1->song_id);
        $this->assertEquals($songA->id, $item2->song_id);
        $this->assertEquals($songB->id, $item3->song_id);
    }
}
