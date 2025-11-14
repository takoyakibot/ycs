<?php

namespace Tests\Feature;

use App\Models\Archive;
use App\Models\Channel;
use App\Models\Song;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChannelArchiveTest extends TestCase
{
    use RefreshDatabase;

    /**
     * アーカイブ一覧を取得できる
     */
    public function test_fetch_archives_returns_archives(): void
    {
        $channel = Channel::factory()->create();
        Archive::factory()->count(3)->create([
            'channel_id' => $channel->channel_id,
            'is_display' => 1,
        ]);

        $response = $this->getJson("/api/channels/{$channel->handle}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'current_page',
                'last_page',
                'per_page',
                'total',
            ]);

        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertGreaterThan(0, count($data));
    }

    /**
     * 存在しないチャンネルでエラーが発生する
     */
    public function test_fetch_archives_fails_with_invalid_channel(): void
    {
        $response = $this->getJson('/api/channels/non-existent-channel');

        $response->assertStatus(404);
    }

    /**
     * 非表示のアーカイブは取得されない
     */
    public function test_fetch_archives_excludes_hidden_archives(): void
    {
        $channel = Channel::factory()->create();
        Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'is_display' => 1,
            'title' => 'Visible Archive',
        ]);
        Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'is_display' => 0,
            'title' => 'Hidden Archive',
        ]);

        $response = $this->getJson("/api/channels/{$channel->handle}");

        $response->assertStatus(200)
            ->assertJsonFragment(['title' => 'Visible Archive'])
            ->assertJsonMissing(['title' => 'Hidden Archive']);
    }

    /**
     * タイムスタンプ一覧を取得できる
     */
    public function test_fetch_timestamps_returns_timestamps(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'is_display' => 1,
        ]);
        TsItem::factory()->count(5)->create([
            'video_id' => $archive->video_id,
            'is_display' => 1,
        ]);

        $response = $this->getJson("/api/channels/{$channel->handle}/timestamps");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'ts_text',
                        'ts_num',
                        'text',
                        'video_id',
                        'archive',
                        'mapping',
                    ],
                ],
                'current_page',
                'last_page',
                'per_page',
                'total',
                'index_map',
                'available_indexes',
            ]);
    }

    /**
     * タイムスタンプ一覧のページネーションが機能する
     */
    public function test_fetch_timestamps_pagination_works(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'is_display' => 1,
        ]);
        TsItem::factory()->count(100)->create([
            'video_id' => $archive->video_id,
            'is_display' => 1,
        ]);

        $response = $this->getJson("/api/channels/{$channel->handle}/timestamps?per_page=10&page=2");

        $response->assertStatus(200)
            ->assertJson([
                'current_page' => 2,
                'per_page' => 10,
                'total' => 100,
                'last_page' => 10,
            ])
            ->assertJsonCount(10, 'data');
    }

    /**
     * タイムスタンプ検索が機能する
     */
    public function test_fetch_timestamps_search_works(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'is_display' => 1,
        ]);
        TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => '残酷な天使のテーゼ',
            'is_display' => 1,
        ]);
        TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'オンリーマイレールガン',
            'is_display' => 1,
        ]);

        $response = $this->getJson("/api/channels/{$channel->handle}/timestamps?search=残酷");

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertGreaterThan(0, count($data));

        // 検索結果に「残酷」を含むテキストが存在することを確認
        $found = false;
        foreach ($data as $item) {
            if (str_contains($item['text'], '残酷')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found);
    }

    /**
     * タイムスタンプのソート機能が動作する（楽曲名順）
     */
    public function test_fetch_timestamps_sort_by_song_name(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'is_display' => 1,
        ]);

        // 楽曲を作成
        $songA = Song::factory()->create(['title' => 'A Song']);
        $songB = Song::factory()->create(['title' => 'B Song']);

        // タイムスタンプを作成
        $tsA = TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'a song text',
            'is_display' => 1,
        ]);
        $tsB = TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'b song text',
            'is_display' => 1,
        ]);

        // マッピングを作成
        TimestampSongMapping::factory()->withSong($songB)->create([
            'normalized_text' => \App\Helpers\TextNormalizer::normalize($tsB->text),
        ]);
        TimestampSongMapping::factory()->withSong($songA)->create([
            'normalized_text' => \App\Helpers\TextNormalizer::normalize($tsA->text),
        ]);

        $response = $this->getJson("/api/channels/{$channel->handle}/timestamps?sort=song_asc");

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertEquals('A Song', $data[0]['mapping']['song']['title']);
        $this->assertEquals('B Song', $data[1]['mapping']['song']['title']);
    }

    /**
     * タイムスタンプのソート機能が動作する（時間降順）
     */
    public function test_fetch_timestamps_sort_by_time_desc(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'is_display' => 1,
        ]);

        TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'ts_num' => 100,
            'text' => 'Early timestamp',
            'is_display' => 1,
        ]);
        TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'ts_num' => 200,
            'text' => 'Late timestamp',
            'is_display' => 1,
        ]);

        $response = $this->getJson("/api/channels/{$channel->handle}/timestamps?sort=time_desc");

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertEquals(200, $data[0]['ts_num']);
        $this->assertEquals(100, $data[1]['ts_num']);
    }

    /**
     * バリデーションエラー: per_pageが範囲外
     */
    public function test_fetch_timestamps_validation_per_page_out_of_range(): void
    {
        $channel = Channel::factory()->create();

        $response = $this->getJson("/api/channels/{$channel->handle}/timestamps?per_page=200");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);
    }

    /**
     * バリデーションエラー: sortが不正な値
     */
    public function test_fetch_timestamps_validation_invalid_sort(): void
    {
        $channel = Channel::factory()->create();

        $response = $this->getJson("/api/channels/{$channel->handle}/timestamps?sort=invalid_sort");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['sort']);
    }

    /**
     * 非表示のタイムスタンプは取得されない
     */
    public function test_fetch_timestamps_excludes_hidden_timestamps(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'is_display' => 1,
        ]);

        TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'Visible timestamp',
            'is_display' => 1,
        ]);
        TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'Hidden timestamp',
            'is_display' => 0,
        ]);

        $response = $this->getJson("/api/channels/{$channel->handle}/timestamps");

        $response->assertStatus(200);

        $data = $response->json('data');
        $texts = array_column($data, 'text');

        $this->assertContains('Visible timestamp', $texts);
        $this->assertNotContains('Hidden timestamp', $texts);
    }

    /**
     * 非表示のアーカイブに紐づくタイムスタンプは取得されない
     */
    public function test_fetch_timestamps_excludes_timestamps_from_hidden_archives(): void
    {
        $channel = Channel::factory()->create();

        $visibleArchive = Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'is_display' => 1,
        ]);
        $hiddenArchive = Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'is_display' => 0,
        ]);

        TsItem::factory()->create([
            'video_id' => $visibleArchive->video_id,
            'text' => 'From visible archive',
            'is_display' => 1,
        ]);
        TsItem::factory()->create([
            'video_id' => $hiddenArchive->video_id,
            'text' => 'From hidden archive',
            'is_display' => 1,
        ]);

        $response = $this->getJson("/api/channels/{$channel->handle}/timestamps");

        $response->assertStatus(200);

        $data = $response->json('data');
        $texts = array_column($data, 'text');

        $this->assertContains('From visible archive', $texts);
        $this->assertNotContains('From hidden archive', $texts);
    }

    /**
     * 「楽曲ではない」とマークされたタイムスタンプは除外される
     */
    public function test_fetch_timestamps_excludes_not_song_items(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'is_display' => 1,
        ]);

        $normalTs = TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'Normal song',
            'is_display' => 1,
        ]);
        $notSongTs = TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'Not a song',
            'is_display' => 1,
        ]);

        // 「楽曲ではない」マッピングを作成（confidence=1.0は手動マークの意味）
        TimestampSongMapping::factory()->create([
            'normalized_text' => \App\Helpers\TextNormalizer::normalize($notSongTs->text),
            'song_id' => null,
            'is_not_song' => true,
            'confidence' => 1.0,
            'is_manual' => true,
        ]);

        $response = $this->getJson("/api/channels/{$channel->handle}/timestamps");

        $response->assertStatus(200);

        $data = $response->json('data');
        $texts = array_column($data, 'text');

        $this->assertContains('Normal song', $texts);
        $this->assertNotContains('Not a song', $texts);
    }

    /**
     * 楽曲マッピング情報が正しく付与される
     */
    public function test_fetch_timestamps_includes_song_mapping(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'is_display' => 1,
        ]);

        $song = Song::factory()->withoutSpotify()->create([
            'title' => 'Test Song',
            'artist' => 'Test Artist',
        ]);

        $ts = TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'test song text',
            'is_display' => 1,
        ]);

        TimestampSongMapping::factory()->withSong($song)->create([
            'normalized_text' => \App\Helpers\TextNormalizer::normalize($ts->text),
        ]);

        $response = $this->getJson("/api/channels/{$channel->handle}/timestamps");

        $response->assertStatus(200)
            ->assertJsonFragment([
                'song' => [
                    'title' => 'Test Song',
                    'artist' => 'Test Artist',
                    'spotify_track_id' => null,
                ],
            ]);
    }
}
