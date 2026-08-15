<?php

namespace Tests\Unit\Services;

use App\Models\Archive;
use App\Models\ChangeList;
use App\Models\Channel;
use App\Models\TimestampReport;
use App\Models\TsItem;
use App\Services\ChangeListService;
use App\Services\ChannelQueryService;
use App\Services\CoverSongTitleExtractorService;
use App\Services\RefreshArchiveService;
use App\Services\VideoAnalyzerService;
use App\Services\YouTubeService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class RefreshArchiveServiceTest extends TestCase
{
    use RefreshDatabase;

    protected RefreshArchiveService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // YouTubeServiceのみモック化（外部API呼び出しを避けるため）
        $this->youtubeService = Mockery::mock(YouTubeService::class);

        // 実際のサービスインスタンスを使用（DBテストのため）
        $changeListService = app(ChangeListService::class);
        $channelQueryService = app(ChannelQueryService::class);
        $videoAnalyzerService = app(VideoAnalyzerService::class);
        $coverSongTitleExtractorService = app(CoverSongTitleExtractorService::class);

        $this->service = new RefreshArchiveService(
            $this->youtubeService,
            $changeListService,
            $channelQueryService,
            $videoAnalyzerService,
            $coverSongTitleExtractorService,
            app(\App\Services\SubtitleFingerprintService::class)
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * アーカイブとタイムスタンプの基本的な取得・登録
     */
    public function test_refresh_archives_basic_flow(): void
    {
        $channel = Channel::factory()->create([
            'channel_id' => 'UC123456789',
            'handle' => 'test-channel',
        ]);

        // YouTubeServiceのモック設定
        $this->youtubeService
            ->shouldReceive('getArchivesAndTsItems')
            ->once()
            ->with($channel->channel_id, [])
            ->andReturn([
                [
                    'id' => Str::uuid()->toString(),
                    'video_id' => 'video123',
                    'channel_id' => $channel->channel_id,
                    'title' => 'Test Archive',
                    'thumbnail' => 'https://example.com/thumb.jpg',
                    'is_public' => true,
                    'is_display' => true,
                    'published_at' => now(),
                    'comments_updated_at' => now(),
                    'description' => 'This will be removed',
                    'ts_items' => [
                        [
                            'id' => Str::uuid()->toString(),
                            'video_id' => 'video123',
                            'type' => '1',
                            'ts_text' => '1:00',
                            'ts_num' => 60,
                            'text' => 'Test Song',
                            'is_display' => true,
                        ],
                    ],
                ],
            ]);

        $count = $this->service->refreshArchives($channel);

        // アーカイブが登録されていることを確認
        $this->assertEquals(1, $count);
        $this->assertDatabaseHas('archives', [
            'video_id' => 'video123',
            'channel_id' => $channel->channel_id,
            'title' => 'Test Archive',
        ]);

        // タイムスタンプが登録されていることを確認
        $this->assertDatabaseHas('ts_items', [
            'video_id' => 'video123',
            'type' => '1',
            'text' => 'Test Song',
        ]);

        // descriptionフィールドは$fillableに含まれておらず、DBに保存されないことを確認
        // (RefreshArchiveServiceでunset()されている)
        $archive = Archive::where('video_id', 'video123')->first();
        // descriptionがnullまたは存在しないことを確認
        $this->assertFalse(isset($archive->description));
    }

    /**
     * change_listの情報がts_itemsに正しく反映される
     */
    public function test_apply_change_list_to_ts_items(): void
    {
        $channel = Channel::factory()->create(['channel_id' => 'UC123456789']);

        // 初期データ作成
        $archive = Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'video_id' => 'video123',
            'is_display' => true,
        ]);

        $tsItem = TsItem::factory()->create([
            'video_id' => 'video123',
            'comment_id' => 'comment123',
            'type' => '2',
            'is_display' => true,
        ]);

        // change_listに非表示設定を追加
        ChangeList::create([
            'channel_id' => $channel->channel_id,
            'video_id' => 'video123',
            'comment_id' => 'comment123',
            'is_display' => false,
        ]);

        // YouTubeServiceのモック設定（空のアーカイブを返す）
        $this->youtubeService
            ->shouldReceive('getArchivesAndTsItems')
            ->once()
            ->andReturn([
                [
                    'id' => $archive->id,
                    'video_id' => 'video123',
                    'channel_id' => $channel->channel_id,
                    'title' => 'Test Archive',
                    'thumbnail' => 'https://example.com/thumb.jpg',
                    'is_public' => true,
                    'is_display' => true,
                    'published_at' => now(),
                    'comments_updated_at' => now(),
                    'description' => '',
                    'ts_items' => [
                        [
                            'id' => $tsItem->id,
                            'video_id' => 'video123',
                            'comment_id' => 'comment123',
                            'type' => '2',
                            'ts_text' => '1:00',
                            'ts_num' => 60,
                            'text' => 'Test',
                            'is_display' => true,
                        ],
                    ],
                ],
            ]);

        $this->service->refreshArchives($channel);

        // ts_itemsのis_displayがfalseに更新されていることを確認
        $this->assertDatabaseHas('ts_items', [
            'video_id' => 'video123',
            'comment_id' => 'comment123',
            'is_display' => false,
        ]);
    }

    /**
     * change_listの情報がarchivesに正しく反映される
     */
    public function test_apply_change_list_to_archives(): void
    {
        $channel = Channel::factory()->create(['channel_id' => 'UC123456789']);

        // 初期データ作成
        $archive = Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'video_id' => 'video123',
            'is_display' => true,
        ]);

        // change_listに非表示設定を追加（comment_id IS NULL = アーカイブ）
        ChangeList::create([
            'channel_id' => $channel->channel_id,
            'video_id' => 'video123',
            'comment_id' => null,
            'is_display' => false,
        ]);

        // YouTubeServiceのモック設定
        $this->youtubeService
            ->shouldReceive('getArchivesAndTsItems')
            ->once()
            ->andReturn([
                [
                    'id' => $archive->id,
                    'video_id' => 'video123',
                    'channel_id' => $channel->channel_id,
                    'title' => 'Test Archive',
                    'thumbnail' => 'https://example.com/thumb.jpg',
                    'is_public' => true,
                    'is_display' => true,
                    'published_at' => now(),
                    'comments_updated_at' => now(),
                    'description' => '',
                    'ts_items' => [],
                ],
            ]);

        $this->service->refreshArchives($channel);

        // archivesのis_displayがfalseに更新されていることを確認
        $this->assertDatabaseHas('archives', [
            'video_id' => 'video123',
            'is_display' => false,
        ]);
    }

    /**
     * 不要なchange_listが削除される（タイムスタンプ）
     */
    public function test_delete_obsolete_change_lists_for_timestamps(): void
    {
        $channel = Channel::factory()->create(['channel_id' => 'UC123456789']);

        // 存在するアーカイブ
        $archive = Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'video_id' => 'video123',
        ]);

        // 存在しないタイムスタンプに紐づくchange_list
        ChangeList::create([
            'channel_id' => $channel->channel_id,
            'video_id' => 'video123',
            'comment_id' => 'nonexistent_comment',
            'is_display' => false,
        ]);

        // YouTubeServiceのモック設定
        $this->youtubeService
            ->shouldReceive('getArchivesAndTsItems')
            ->once()
            ->andReturn([
                [
                    'id' => $archive->id,
                    'video_id' => 'video123',
                    'channel_id' => $channel->channel_id,
                    'title' => 'Test Archive',
                    'thumbnail' => 'https://example.com/thumb.jpg',
                    'is_public' => true,
                    'is_display' => true,
                    'published_at' => now(),
                    'comments_updated_at' => now(),
                    'description' => '',
                    'ts_items' => [],
                ],
            ]);

        // getTimeStampsFromCommentsのモック設定
        // change_listに存在するcomment_idに対して呼ばれる可能性があるため許可
        $this->youtubeService
            ->shouldReceive('getTimeStampsFromComments')
            ->andReturn([]);

        $this->service->refreshArchives($channel);

        // 不要なchange_listが削除されていることを確認
        $this->assertDatabaseMissing('change_list', [
            'video_id' => 'video123',
            'comment_id' => 'nonexistent_comment',
        ]);
    }

    /**
     * 不要なchange_listが削除される（アーカイブ）
     */
    public function test_delete_obsolete_change_lists_for_archives(): void
    {
        $channel = Channel::factory()->create(['channel_id' => 'UC123456789']);

        // 存在しないアーカイブに紐づくchange_list
        ChangeList::create([
            'channel_id' => $channel->channel_id,
            'video_id' => 'nonexistent_video',
            'comment_id' => null,
            'is_display' => false,
        ]);

        // YouTubeServiceのモック設定（空のアーカイブリストを返す）
        $this->youtubeService
            ->shouldReceive('getArchivesAndTsItems')
            ->once()
            ->andReturn([]);

        $this->service->refreshArchives($channel);

        // 不要なchange_listが削除されていることを確認
        $this->assertDatabaseMissing('change_list', [
            'video_id' => 'nonexistent_video',
        ]);
    }

    /**
     * 必要なchange_listは削除されない
     */
    public function test_keep_necessary_change_lists(): void
    {
        $channel = Channel::factory()->create(['channel_id' => 'UC123456789']);

        $archive = Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'video_id' => 'video123',
            'is_display' => true,
        ]);

        // 存在するアーカイブに紐づくchange_list
        ChangeList::create([
            'channel_id' => $channel->channel_id,
            'video_id' => 'video123',
            'comment_id' => null,
            'is_display' => false,
        ]);

        // YouTubeServiceのモック設定
        $this->youtubeService
            ->shouldReceive('getArchivesAndTsItems')
            ->once()
            ->andReturn([
                [
                    'id' => $archive->id,
                    'video_id' => 'video123',
                    'channel_id' => $channel->channel_id,
                    'title' => 'Test Archive',
                    'thumbnail' => 'https://example.com/thumb.jpg',
                    'is_public' => true,
                    'is_display' => true,
                    'published_at' => now(),
                    'comments_updated_at' => now(),
                    'description' => '',
                    'ts_items' => [],
                ],
            ]);

        $this->service->refreshArchives($channel);

        // 必要なchange_listは残っていることを確認
        $this->assertDatabaseHas('change_list', [
            'video_id' => 'video123',
            'comment_id' => null,
        ]);
    }

    /**
     * YouTube API接続エラーのハンドリング
     */
    public function test_handle_youtube_api_error(): void
    {
        $channel = Channel::factory()->create(['channel_id' => 'UC123456789']);

        // YouTubeServiceがExceptionを投げるように設定
        $this->youtubeService
            ->shouldReceive('getArchivesAndTsItems')
            ->once()
            ->andThrow(new Exception('YouTube API Error'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('youtubeとの接続でエラーが発生しました');

        $this->service->refreshArchives($channel);
    }

    /**
     * 既存データの置き換え
     * refreshArchivesが既存のアーカイブを削除し、新しいデータで置き換えることを確認
     */
    public function test_refresh_archives_replaces_existing_data(): void
    {
        $channel = Channel::factory()->create(['channel_id' => 'UC123456789']);

        // 既存のアーカイブを作成
        $existingArchive = Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'video_id' => 'existing_video',
        ]);

        // 既存のタイムスタンプを作成
        TsItem::factory()->create([
            'video_id' => 'existing_video',
            'type' => '1',
            'text' => 'Old Song',
        ]);

        // YouTubeServiceのモック設定
        $this->youtubeService
            ->shouldReceive('getArchivesAndTsItems')
            ->once()
            ->andReturn([
                [
                    'id' => Str::uuid()->toString(),
                    'video_id' => 'new_video',
                    'channel_id' => $channel->channel_id,
                    'title' => 'New Archive',
                    'thumbnail' => 'https://example.com/thumb.jpg',
                    'is_public' => true,
                    'is_display' => true,
                    'published_at' => now(),
                    'comments_updated_at' => now(),
                    'description' => '',
                    'ts_items' => [
                        [
                            'id' => Str::uuid()->toString(),
                            'video_id' => 'new_video',
                            'type' => '1',
                            'ts_text' => '1:00',
                            'ts_num' => 60,
                            'text' => 'New Song',
                            'is_display' => true,
                        ],
                    ],
                ],
            ]);

        $this->service->refreshArchives($channel);

        // 既存のアーカイブとタイムスタンプが削除されることを確認
        $this->assertDatabaseMissing('archives', [
            'video_id' => 'existing_video',
        ]);
        $this->assertDatabaseMissing('ts_items', [
            'video_id' => 'existing_video',
            'text' => 'Old Song',
        ]);

        // 新しいアーカイブとタイムスタンプが登録されることを確認
        $this->assertDatabaseHas('archives', [
            'video_id' => 'new_video',
            'title' => 'New Archive',
        ]);
        $this->assertDatabaseHas('ts_items', [
            'video_id' => 'new_video',
            'text' => 'New Song',
        ]);
    }

    /**
     * 複数のアーカイブとタイムスタンプの処理
     */
    public function test_handle_multiple_archives_and_timestamps(): void
    {
        $channel = Channel::factory()->create(['channel_id' => 'UC123456789']);

        // YouTubeServiceのモック設定
        $this->youtubeService
            ->shouldReceive('getArchivesAndTsItems')
            ->once()
            ->andReturn([
                [
                    'id' => Str::uuid()->toString(),
                    'video_id' => 'video1',
                    'channel_id' => $channel->channel_id,
                    'title' => 'Archive 1',
                    'thumbnail' => 'https://example.com/thumb1.jpg',
                    'is_public' => true,
                    'is_display' => true,
                    'published_at' => now(),
                    'comments_updated_at' => now(),
                    'description' => '',
                    'ts_items' => [
                        [
                            'id' => Str::uuid()->toString(),
                            'video_id' => 'video1',
                            'type' => '1',
                            'ts_text' => '1:00',
                            'ts_num' => 60,
                            'text' => 'Song 1',
                            'is_display' => true,
                        ],
                        [
                            'id' => Str::uuid()->toString(),
                            'video_id' => 'video1',
                            'type' => '1',
                            'ts_text' => '2:00',
                            'ts_num' => 120,
                            'text' => 'Song 2',
                            'is_display' => true,
                        ],
                    ],
                ],
                [
                    'id' => Str::uuid()->toString(),
                    'video_id' => 'video2',
                    'channel_id' => $channel->channel_id,
                    'title' => 'Archive 2',
                    'thumbnail' => 'https://example.com/thumb2.jpg',
                    'is_public' => true,
                    'is_display' => true,
                    'published_at' => now(),
                    'comments_updated_at' => now(),
                    'description' => '',
                    'ts_items' => [
                        [
                            'id' => Str::uuid()->toString(),
                            'video_id' => 'video2',
                            'type' => '1',
                            'ts_text' => '1:30',
                            'ts_num' => 90,
                            'text' => 'Song 3',
                            'is_display' => true,
                        ],
                    ],
                ],
            ]);

        $count = $this->service->refreshArchives($channel);

        $this->assertEquals(2, $count);

        // アーカイブが登録されていることを確認
        $this->assertDatabaseHas('archives', ['video_id' => 'video1']);
        $this->assertDatabaseHas('archives', ['video_id' => 'video2']);

        // タイムスタンプが登録されていることを確認
        $this->assertDatabaseHas('ts_items', ['video_id' => 'video1', 'text' => 'Song 1']);
        $this->assertDatabaseHas('ts_items', ['video_id' => 'video1', 'text' => 'Song 2']);
        $this->assertDatabaseHas('ts_items', ['video_id' => 'video2', 'text' => 'Song 3']);
    }

    /**
     * change_listの適用が正しく行われる（複雑なシナリオ）
     */
    public function test_complex_change_list_scenario(): void
    {
        $channel = Channel::factory()->create(['channel_id' => 'UC123456789']);

        // 既存データ
        $archive1 = Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'video_id' => 'video1',
            'is_display' => true,
        ]);

        $tsItem1 = TsItem::factory()->create([
            'video_id' => 'video1',
            'comment_id' => 'comment1',
            'type' => '2',
            'is_display' => true,
        ]);

        $tsItem2 = TsItem::factory()->create([
            'video_id' => 'video1',
            'comment_id' => 'comment2',
            'type' => '2',
            'is_display' => true,
        ]);

        // change_listの設定
        // - archive1を非表示
        ChangeList::create([
            'channel_id' => $channel->channel_id,
            'video_id' => 'video1',
            'comment_id' => null,
            'is_display' => false,
        ]);

        // - tsItem1を非表示
        ChangeList::create([
            'channel_id' => $channel->channel_id,
            'video_id' => 'video1',
            'comment_id' => 'comment1',
            'is_display' => false,
        ]);

        // - tsItem2は表示のまま（change_listなし）

        // YouTubeServiceのモック設定
        $this->youtubeService
            ->shouldReceive('getArchivesAndTsItems')
            ->once()
            ->andReturn([
                [
                    'id' => $archive1->id,
                    'video_id' => 'video1',
                    'channel_id' => $channel->channel_id,
                    'title' => 'Archive 1',
                    'thumbnail' => 'https://example.com/thumb.jpg',
                    'is_public' => true,
                    'is_display' => true,
                    'published_at' => now(),
                    'comments_updated_at' => now(),
                    'description' => '',
                    'ts_items' => [
                        [
                            'id' => $tsItem1->id,
                            'video_id' => 'video1',
                            'comment_id' => 'comment1',
                            'type' => '2',
                            'ts_text' => '1:00',
                            'ts_num' => 60,
                            'text' => 'Song 1',
                            'is_display' => true,
                        ],
                        [
                            'id' => $tsItem2->id,
                            'video_id' => 'video1',
                            'comment_id' => 'comment2',
                            'type' => '2',
                            'ts_text' => '2:00',
                            'ts_num' => 120,
                            'text' => 'Song 2',
                            'is_display' => true,
                        ],
                    ],
                ],
            ]);

        $this->service->refreshArchives($channel);

        // 結果確認
        $this->assertDatabaseHas('archives', [
            'video_id' => 'video1',
            'is_display' => false, // change_listにより非表示
        ]);

        $this->assertDatabaseHas('ts_items', [
            'video_id' => 'video1',
            'comment_id' => 'comment1',
            'is_display' => false, // change_listにより非表示
        ]);

        $this->assertDatabaseHas('ts_items', [
            'video_id' => 'video1',
            'comment_id' => 'comment2',
            'is_display' => true, // 表示のまま
        ]);
    }

    /**
     * refreshTimeStampsFromComments のテスト
     */
    public function test_refresh_timestamps_from_comments(): void
    {
        $videoId = 'video123';

        // アーカイブを先に作成（外部キー制約のため）
        $channel = Channel::factory()->create();
        Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'video_id' => $videoId,
        ]);

        // 既存のtype=2のタイムスタンプ
        TsItem::factory()->create([
            'video_id' => $videoId,
            'comment_id' => 'old_comment',
            'type' => '2',
            'text' => 'Old Song',
        ]);

        // YouTubeServiceのモック設定
        $this->youtubeService
            ->shouldReceive('getTimeStampsFromComments')
            ->once()
            ->with($videoId, [])
            ->andReturn([
                [
                    'id' => Str::uuid()->toString(),
                    'video_id' => $videoId,
                    'comment_id' => 'new_comment',
                    'type' => '2',
                    'ts_text' => '1:00',
                    'ts_num' => 60,
                    'text' => 'New Song',
                    'is_display' => true,
                ],
            ]);

        $this->service->refreshTimeStampsFromComments($videoId);

        // 古いタイムスタンプが削除されていることを確認
        $this->assertDatabaseMissing('ts_items', [
            'video_id' => $videoId,
            'comment_id' => 'old_comment',
        ]);

        // 新しいタイムスタンプが登録されていることを確認
        $this->assertDatabaseHas('ts_items', [
            'video_id' => $videoId,
            'comment_id' => 'new_comment',
            'text' => 'New Song',
        ]);
    }

    /**
     * refreshTimeStampsFromComments でAPIエラーが発生した場合
     */
    public function test_refresh_timestamps_from_comments_handles_api_error(): void
    {
        $videoId = 'video123';

        // YouTubeServiceがExceptionを投げるように設定
        $this->youtubeService
            ->shouldReceive('getTimeStampsFromComments')
            ->once()
            ->andThrow(new Exception('YouTube API Error'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('youtubeとの接続でエラーが発生しました');

        $this->service->refreshTimeStampsFromComments($videoId);
    }

    /**
     * isCoverSong: 「歌ってみた」を含むタイトルを検出
     */
    public function test_is_cover_song_detects_utatte_mita(): void
    {
        $this->assertTrue($this->service->isCoverSong('【歌ってみた】夜に駆ける / YOASOBI'));
        $this->assertTrue($this->service->isCoverSong('夜に駆ける 歌ってみた'));
    }

    /**
     * isCoverSong: 「cover」を含むタイトルを検出（大文字小文字無視）
     */
    public function test_is_cover_song_detects_cover(): void
    {
        $this->assertTrue($this->service->isCoverSong('【Cover】夜に駆ける'));
        $this->assertTrue($this->service->isCoverSong('夜に駆ける cover'));
        $this->assertTrue($this->service->isCoverSong('夜に駆ける COVER'));
    }

    /**
     * isCoverSong: 「カバー」を含むタイトルを検出
     */
    public function test_is_cover_song_detects_katakana_cover(): void
    {
        $this->assertTrue($this->service->isCoverSong('夜に駆ける【カバー】'));
        $this->assertTrue($this->service->isCoverSong('カバー曲集'));
    }

    /**
     * isCoverSong: 関連キーワードを含まないタイトルはfalse
     */
    public function test_is_cover_song_returns_false_for_normal_title(): void
    {
        $this->assertFalse($this->service->isCoverSong('【歌枠】歌います！'));
        $this->assertFalse($this->service->isCoverSong('雑談配信'));
        $this->assertFalse($this->service->isCoverSong('ゲーム実況'));
    }

    /**
     * createCoverSongTsItem: カバー曲用のts_itemが正しく生成される
     */
    public function test_create_cover_song_ts_item(): void
    {
        $channel = Channel::factory()->create();
        $archive = [
            'video_id' => 'video123',
            'title' => '【歌ってみた】夜に駆ける / YOASOBI',
        ];

        $tsItem = $this->service->createCoverSongTsItem($archive, $channel->channel_id);

        $this->assertEquals('video123', $tsItem['video_id']);
        $this->assertEquals('video123', $tsItem['comment_id']);
        $this->assertEquals('3', $tsItem['type']);
        $this->assertEquals('0:00', $tsItem['ts_text']);
        $this->assertEquals(0, $tsItem['ts_num']);
        // 楽曲名が抽出される
        $this->assertStringContainsString('夜に駆ける', $tsItem['text']);
        $this->assertTrue($tsItem['is_display']);
        $this->assertNotEmpty($tsItem['id']);
        $this->assertNotEmpty($tsItem['normalized_text']);
    }

    /**
     * extractCoverSongTsItems: カバー曲のみがts_itemsとして抽出される
     */
    public function test_extract_cover_song_ts_items(): void
    {
        $channel = Channel::factory()->create();
        $archives = [
            ['video_id' => 'video1', 'title' => '【歌ってみた】夜に駆ける'],
            ['video_id' => 'video2', 'title' => '【歌枠】歌います！'],
            ['video_id' => 'video3', 'title' => '【Cover】群青'],
            ['video_id' => 'video4', 'title' => '雑談配信'],
        ];

        $coverTsItems = $this->service->extractCoverSongTsItems($archives, $channel->channel_id);

        // video1とvideo3のみがカバー曲として抽出される
        $this->assertCount(2, $coverTsItems);

        $videoIds = array_column($coverTsItems, 'video_id');
        $this->assertContains('video1', $videoIds);
        $this->assertContains('video3', $videoIds);
        $this->assertNotContains('video2', $videoIds);
        $this->assertNotContains('video4', $videoIds);
    }

    /**
     * refreshArchives: カバー曲動画が0:00のts_itemとして登録される
     */
    public function test_refresh_archives_registers_cover_songs(): void
    {
        $channel = Channel::factory()->create(['channel_id' => 'UC123456789']);

        // YouTubeServiceのモック設定
        $this->youtubeService
            ->shouldReceive('getArchivesAndTsItems')
            ->once()
            ->andReturn([
                [
                    'id' => Str::uuid()->toString(),
                    'video_id' => 'cover_video',
                    'channel_id' => $channel->channel_id,
                    'title' => '【歌ってみた】夜に駆ける / YOASOBI',
                    'thumbnail' => 'https://example.com/thumb.jpg',
                    'is_public' => true,
                    'is_display' => true,
                    'published_at' => now(),
                    'comments_updated_at' => now(),
                    'description' => '',
                    'ts_items' => [], // 概要欄にタイムスタンプなし
                ],
                [
                    'id' => Str::uuid()->toString(),
                    'video_id' => 'normal_video',
                    'channel_id' => $channel->channel_id,
                    'title' => '【歌枠】歌います！',
                    'thumbnail' => 'https://example.com/thumb2.jpg',
                    'is_public' => true,
                    'is_display' => true,
                    'published_at' => now(),
                    'comments_updated_at' => now(),
                    'description' => '',
                    'ts_items' => [
                        [
                            'id' => Str::uuid()->toString(),
                            'video_id' => 'normal_video',
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

        // カバー曲動画に0:00のts_itemが登録されていることを確認
        // CoverSongTitleExtractorServiceにより楽曲名が抽出される
        $this->assertDatabaseHas('ts_items', [
            'video_id' => 'cover_video',
            'type' => '3',
            'ts_text' => '0:00',
            'ts_num' => 0,
            'text' => '夜に駆ける / YOASOBI',
        ]);

        // 通常動画にはカバー曲のts_itemがないことを確認
        $this->assertDatabaseMissing('ts_items', [
            'video_id' => 'normal_video',
            'type' => '3',
        ]);

        // 通常動画の概要欄タイムスタンプは登録されていることを確認
        $this->assertDatabaseHas('ts_items', [
            'video_id' => 'normal_video',
            'type' => '1',
            'text' => 'Test Song',
        ]);
    }

    /**
     * refreshArchives: カバー曲動画でも概要欄のタイムスタンプがあれば両方登録される
     */
    public function test_refresh_archives_registers_both_cover_and_timestamps(): void
    {
        $channel = Channel::factory()->create(['channel_id' => 'UC123456789']);

        // YouTubeServiceのモック設定
        $this->youtubeService
            ->shouldReceive('getArchivesAndTsItems')
            ->once()
            ->andReturn([
                [
                    'id' => Str::uuid()->toString(),
                    'video_id' => 'cover_video',
                    'channel_id' => $channel->channel_id,
                    'title' => '【Cover】カバー曲集',
                    'thumbnail' => 'https://example.com/thumb.jpg',
                    'is_public' => true,
                    'is_display' => true,
                    'published_at' => now(),
                    'comments_updated_at' => now(),
                    'description' => '',
                    'ts_items' => [
                        [
                            'id' => Str::uuid()->toString(),
                            'video_id' => 'cover_video',
                            'type' => '1',
                            'ts_text' => '1:00',
                            'ts_num' => 60,
                            'text' => '夜に駆ける',
                            'is_display' => true,
                        ],
                        [
                            'id' => Str::uuid()->toString(),
                            'video_id' => 'cover_video',
                            'type' => '1',
                            'ts_text' => '5:00',
                            'ts_num' => 300,
                            'text' => '群青',
                            'is_display' => true,
                        ],
                    ],
                ],
            ]);

        $this->service->refreshArchives($channel);

        // カバー曲として0:00のts_itemが登録される
        $this->assertDatabaseHas('ts_items', [
            'video_id' => 'cover_video',
            'type' => '3',
            'ts_text' => '0:00',
            'ts_num' => 0,
        ]);

        // 概要欄のタイムスタンプも登録される
        $this->assertDatabaseHas('ts_items', [
            'video_id' => 'cover_video',
            'type' => '1',
            'text' => '夜に駆ける',
        ]);
        $this->assertDatabaseHas('ts_items', [
            'video_id' => 'cover_video',
            'type' => '1',
            'text' => '群青',
        ]);

        // 合計3件のts_itemが登録されていること
        $this->assertEquals(3, TsItem::where('video_id', 'cover_video')->count());
    }

    /**
     * アーカイブ更新時に報告が維持されることをテスト
     */
    public function test_refresh_archives_preserves_reports(): void
    {
        $channel = Channel::factory()->create(['channel_id' => 'UC123456789']);

        // 既存のアーカイブとタイムスタンプを作成
        Archive::create([
            'id' => 'video123',
            'video_id' => 'video123',
            'channel_id' => $channel->channel_id,
            'title' => 'Test Archive',
            'thumbnail' => 'https://example.com/thumb.jpg',
            'is_public' => true,
            'is_display' => true,
            'published_at' => now(),
            'comments_updated_at' => now(),
        ]);

        TsItem::create([
            'id' => Str::ulid(),
            'video_id' => 'video123',
            'type' => '1',
            'ts_text' => '1:00',
            'ts_num' => 60,
            'text' => 'Test Song',
            'is_display' => true,
        ]);

        // 報告を作成
        $report = TimestampReport::create([
            'video_id' => 'video123',
            'ts_text' => '1:00',
            'ts_num' => 60,
            'report_type' => 'wrong_song',
            'reporter_ip' => '127.0.0.1',
        ]);

        // YouTubeServiceのモック設定（同じタイムスタンプを返す）
        $this->youtubeService
            ->shouldReceive('getArchivesAndTsItems')
            ->once()
            ->andReturn([
                [
                    'id' => Str::uuid()->toString(),
                    'video_id' => 'video123',
                    'channel_id' => $channel->channel_id,
                    'title' => 'Test Archive Updated',
                    'thumbnail' => 'https://example.com/thumb.jpg',
                    'is_public' => true,
                    'is_display' => true,
                    'published_at' => now(),
                    'comments_updated_at' => now(),
                    'description' => '',
                    'ts_items' => [
                        [
                            'id' => Str::uuid()->toString(),
                            'video_id' => 'video123',
                            'type' => '1',
                            'ts_text' => '1:00',
                            'ts_num' => 60,
                            'text' => 'Test Song Updated',
                            'is_display' => true,
                        ],
                    ],
                ],
            ]);

        $this->service->refreshArchives($channel);

        // 報告が維持されていることを確認
        $this->assertDatabaseHas('timestamp_reports', [
            'id' => $report->id,
            'video_id' => 'video123',
            'ts_text' => '1:00',
            'ts_num' => 60,
        ]);
    }

    /**
     * アーカイブ更新時にts_itemが消えた報告が削除されることをテスト
     */
    public function test_refresh_archives_deletes_obsolete_reports(): void
    {
        $channel = Channel::factory()->create(['channel_id' => 'UC123456789']);

        // 既存のアーカイブとタイムスタンプを作成
        Archive::create([
            'id' => 'video123',
            'video_id' => 'video123',
            'channel_id' => $channel->channel_id,
            'title' => 'Test Archive',
            'thumbnail' => 'https://example.com/thumb.jpg',
            'is_public' => true,
            'is_display' => true,
            'published_at' => now(),
            'comments_updated_at' => now(),
        ]);

        TsItem::create([
            'id' => Str::ulid(),
            'video_id' => 'video123',
            'type' => '1',
            'ts_text' => '1:00',
            'ts_num' => 60,
            'text' => 'Test Song',
            'is_display' => true,
        ]);

        // 報告を作成
        $report = TimestampReport::create([
            'video_id' => 'video123',
            'ts_text' => '1:00',
            'ts_num' => 60,
            'report_type' => 'wrong_song',
            'reporter_ip' => '127.0.0.1',
        ]);

        // YouTubeServiceのモック設定（タイムスタンプが変更された）
        $this->youtubeService
            ->shouldReceive('getArchivesAndTsItems')
            ->once()
            ->andReturn([
                [
                    'id' => Str::uuid()->toString(),
                    'video_id' => 'video123',
                    'channel_id' => $channel->channel_id,
                    'title' => 'Test Archive',
                    'thumbnail' => 'https://example.com/thumb.jpg',
                    'is_public' => true,
                    'is_display' => true,
                    'published_at' => now(),
                    'comments_updated_at' => now(),
                    'description' => '',
                    'ts_items' => [
                        [
                            'id' => Str::uuid()->toString(),
                            'video_id' => 'video123',
                            'type' => '1',
                            'ts_text' => '2:00', // 時間が変更された
                            'ts_num' => 120,
                            'text' => 'Test Song',
                            'is_display' => true,
                        ],
                    ],
                ],
            ]);

        $this->service->refreshArchives($channel);

        // 古いタイムスタンプに紐づく報告が削除されていることを確認
        $this->assertDatabaseMissing('timestamp_reports', [
            'id' => $report->id,
        ]);
    }

    /**
     * 他のチャンネルの報告は削除されないことをテスト
     */
    /**
     * アーカイブ更新後も字幕データが残り、フィンガープリントが再生成され、
     * 字幕なしフラグが引き継がれることを検証する（#622）
     */
    public function test_refresh_archives_preserves_subtitles_and_regenerates_fingerprints(): void
    {
        $channel = Channel::factory()->create(['channel_id' => 'UC123456789']);

        // 更新前の状態: 字幕＋FPありのアーカイブと、字幕なしフラグ付きのアーカイブ
        Archive::create([
            'id' => Str::ulid(),
            'video_id' => 'video123abc',
            'channel_id' => $channel->channel_id,
            'title' => 'Subtitled Archive',
            'is_public' => true,
            'is_display' => true,
            'published_at' => now(),
            'comments_updated_at' => now(),
        ]);
        $oldTsItem = TsItem::create([
            'id' => Str::ulid(),
            'video_id' => 'video123abc',
            'type' => '1',
            'ts_text' => '1:00',
            'ts_num' => 60,
            'text' => 'テスト曲',
            'is_display' => true,
        ]);
        \App\Models\VideoSubtitle::create([
            'id' => Str::ulid(),
            'video_id' => 'video123abc',
            'language_code' => 'ja',
            'kind' => 'asr',
            'subtitle_data' => [
                ['start' => 60, 'duration' => 5, 'text' => 'あいうえおかきくけこさしすせそたちつてとなにぬねの'],
            ],
            'segment_count' => 1,
        ]);
        \App\Models\SubtitleFingerprint::create([
            'id' => Str::ulid(),
            'video_id' => 'video123abc',
            'ts_item_id' => $oldTsItem->id,
            'start_sec' => 60,
            'duration_sec' => \App\Services\SubtitleFingerprintService::WINDOW_DURATION_SEC,
            'fingerprint_text' => 'old',
            'trigrams' => ['old'],
        ]);

        Archive::create([
            'id' => Str::ulid(),
            'video_id' => 'video456def',
            'channel_id' => $channel->channel_id,
            'title' => 'No Subtitle Archive',
            'is_public' => true,
            'is_display' => true,
            'published_at' => now(),
            'comments_updated_at' => now(),
            'subtitles_unavailable_at' => now()->subDay(),
        ]);

        // 更新: 同じ動画が新しいIDのts_itemsで返ってくる
        $this->youtubeService
            ->shouldReceive('getArchivesAndTsItems')
            ->once()
            ->andReturn([
                [
                    'id' => Str::uuid()->toString(),
                    'video_id' => 'video123abc',
                    'channel_id' => $channel->channel_id,
                    'title' => 'Subtitled Archive',
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
                            'type' => '1',
                            'ts_text' => '1:00',
                            'ts_num' => 60,
                            'text' => 'テスト曲',
                            'is_display' => true,
                        ],
                    ],
                ],
                [
                    'id' => Str::uuid()->toString(),
                    'video_id' => 'video456def',
                    'channel_id' => $channel->channel_id,
                    'title' => 'No Subtitle Archive',
                    'thumbnail' => '',
                    'is_public' => true,
                    'is_display' => true,
                    'published_at' => now(),
                    'comments_updated_at' => now(),
                    'description' => '',
                    'ts_items' => [
                        [
                            'id' => Str::uuid()->toString(),
                            'video_id' => 'video456def',
                            'type' => '1',
                            'ts_text' => '2:00',
                            'ts_num' => 120,
                            'text' => '別の曲',
                            'is_display' => true,
                        ],
                    ],
                ],
            ]);

        $this->service->refreshArchives($channel);

        // 字幕データが生き残っている
        $this->assertDatabaseHas('video_subtitles', ['video_id' => 'video123abc']);

        // 字幕なしフラグが引き継がれている
        $this->assertNotNull(
            Archive::where('video_id', 'video456def')->first()->subtitles_unavailable_at
        );

        // フィンガープリントが新しいts_itemを指して再生成されている
        $fps = \App\Models\SubtitleFingerprint::where('video_id', 'video123abc')->get();
        $this->assertCount(1, $fps);
        $newTsItem = TsItem::where('video_id', 'video123abc')->first();
        $this->assertEquals($newTsItem->id, $fps->first()->ts_item_id);
        $this->assertNotEquals($oldTsItem->id, $fps->first()->ts_item_id);
    }

    /**
     * 概要欄由来のタイムスタンプが全て非表示にされた歌枠は、
     * 更新時にコメントからタイムスタンプを自動取得する（#628）
     */
    public function test_refresh_fetches_comments_when_all_description_timestamps_hidden(): void
    {
        $channel = Channel::factory()->create(['channel_id' => 'UC123456789']);

        // 更新前の状態: 概要欄由来（type=1）のts_itemsが全て非表示、コメント由来なし
        Archive::create([
            'id' => Str::ulid(),
            'video_id' => 'schedule0001',
            'channel_id' => $channel->channel_id,
            'title' => '歌枠アーカイブ',
            'is_public' => true,
            'is_display' => true,
            'published_at' => now(),
            'comments_updated_at' => now(),
        ]);
        foreach ([['0:00', 0, 'イベントA'], ['10:00', 600, 'イベントB']] as [$tsText, $tsNum, $text]) {
            TsItem::create([
                'id' => Str::ulid(),
                'video_id' => 'schedule0001',
                'type' => '1',
                'ts_text' => $tsText,
                'ts_num' => $tsNum,
                'text' => $text,
                'is_display' => false,
            ]);
        }

        // 更新: 概要欄タイムスタンプが2件以上返る（イベントスケジュール）
        $this->youtubeService
            ->shouldReceive('getArchivesAndTsItems')
            ->once()
            ->andReturn([
                [
                    'id' => Str::uuid()->toString(),
                    'video_id' => 'schedule0001',
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
                            'video_id' => 'schedule0001',
                            'type' => '1',
                            'ts_text' => '0:00',
                            'ts_num' => 0,
                            'text' => 'イベントA',
                            'is_display' => true,
                        ],
                        [
                            'id' => Str::uuid()->toString(),
                            'video_id' => 'schedule0001',
                            'type' => '1',
                            'ts_text' => '10:00',
                            'ts_num' => 600,
                            'text' => 'イベントB',
                            'is_display' => true,
                        ],
                    ],
                ],
            ]);

        // コメント取得が呼ばれ、楽曲タイムスタンプが返る
        $this->youtubeService
            ->shouldReceive('getTimeStampsFromComments')
            ->once()
            ->with('schedule0001', [])
            ->andReturn([
                [
                    'id' => Str::uuid()->toString(),
                    'comment_id' => 'comment-abc',
                    'video_id' => 'schedule0001',
                    'type' => '2',
                    'ts_text' => '5:00',
                    'ts_num' => 300,
                    'text' => 'テスト曲',
                    'is_display' => true,
                ],
            ]);

        $this->service->refreshArchives($channel);

        // コメント由来のタイムスタンプが取り込まれている
        $this->assertDatabaseHas('ts_items', [
            'video_id' => 'schedule0001',
            'type' => '2',
            'text' => 'テスト曲',
        ]);
    }

    /**
     * 概要欄タイムスタンプが表示中の動画では、コメント取得は従来どおり発動しない（#628）
     */
    public function test_refresh_does_not_fetch_comments_when_description_timestamps_visible(): void
    {
        $channel = Channel::factory()->create(['channel_id' => 'UC123456789']);

        Archive::create([
            'id' => Str::ulid(),
            'video_id' => 'normalvideo1',
            'channel_id' => $channel->channel_id,
            'title' => '歌枠アーカイブ',
            'is_public' => true,
            'is_display' => true,
            'published_at' => now(),
            'comments_updated_at' => now(),
        ]);
        TsItem::create([
            'id' => Str::ulid(),
            'video_id' => 'normalvideo1',
            'type' => '1',
            'ts_text' => '1:00',
            'ts_num' => 60,
            'text' => '曲A',
            'is_display' => true,
        ]);
        TsItem::create([
            'id' => Str::ulid(),
            'video_id' => 'normalvideo1',
            'type' => '1',
            'ts_text' => '2:00',
            'ts_num' => 120,
            'text' => '曲B',
            'is_display' => false,
        ]);

        $this->youtubeService
            ->shouldReceive('getArchivesAndTsItems')
            ->once()
            ->andReturn([
                [
                    'id' => Str::uuid()->toString(),
                    'video_id' => 'normalvideo1',
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
                            'video_id' => 'normalvideo1',
                            'type' => '1',
                            'ts_text' => '1:00',
                            'ts_num' => 60,
                            'text' => '曲A',
                            'is_display' => true,
                        ],
                    ],
                ],
            ]);

        // コメント取得は呼ばれない
        $this->youtubeService->shouldNotReceive('getTimeStampsFromComments');

        $this->service->refreshArchives($channel);

        $this->assertDatabaseMissing('ts_items', ['video_id' => 'normalvideo1', 'type' => '2']);
    }

    public function test_refresh_archives_does_not_delete_other_channel_reports(): void
    {
        $channel1 = Channel::factory()->create(['channel_id' => 'UC111111111']);
        $channel2 = Channel::factory()->create(['channel_id' => 'UC222222222']);

        // チャンネル1のアーカイブとタイムスタンプ
        Archive::create([
            'id' => 'video111',
            'video_id' => 'video111',
            'channel_id' => $channel1->channel_id,
            'title' => 'Channel 1 Archive',
            'thumbnail' => 'https://example.com/thumb.jpg',
            'is_public' => true,
            'is_display' => true,
            'published_at' => now(),
            'comments_updated_at' => now(),
        ]);

        TsItem::create([
            'id' => Str::ulid(),
            'video_id' => 'video111',
            'type' => '1',
            'ts_text' => '1:00',
            'ts_num' => 60,
            'text' => 'Song 1',
            'is_display' => true,
        ]);

        // チャンネル2のアーカイブとタイムスタンプ
        Archive::create([
            'id' => 'video222',
            'video_id' => 'video222',
            'channel_id' => $channel2->channel_id,
            'title' => 'Channel 2 Archive',
            'thumbnail' => 'https://example.com/thumb.jpg',
            'is_public' => true,
            'is_display' => true,
            'published_at' => now(),
            'comments_updated_at' => now(),
        ]);

        TsItem::create([
            'id' => Str::ulid(),
            'video_id' => 'video222',
            'type' => '1',
            'ts_text' => '3:00',
            'ts_num' => 180,
            'text' => 'Song 2',
            'is_display' => true,
        ]);

        // チャンネル2の報告を作成
        $report2 = TimestampReport::create([
            'video_id' => 'video222',
            'ts_text' => '3:00',
            'ts_num' => 180,
            'report_type' => 'wrong_song',
            'reporter_ip' => '127.0.0.1',
        ]);

        // YouTubeServiceのモック設定（チャンネル1のみ更新）
        $this->youtubeService
            ->shouldReceive('getArchivesAndTsItems')
            ->once()
            ->andReturn([
                [
                    'id' => Str::uuid()->toString(),
                    'video_id' => 'video111',
                    'channel_id' => $channel1->channel_id,
                    'title' => 'Channel 1 Archive Updated',
                    'thumbnail' => 'https://example.com/thumb.jpg',
                    'is_public' => true,
                    'is_display' => true,
                    'published_at' => now(),
                    'comments_updated_at' => now(),
                    'description' => '',
                    'ts_items' => [],
                ],
            ]);

        $this->service->refreshArchives($channel1);

        // チャンネル2の報告は残っていることを確認
        $this->assertDatabaseHas('timestamp_reports', [
            'id' => $report2->id,
        ]);
    }
}
