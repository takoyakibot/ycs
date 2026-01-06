<?php

namespace Tests\Unit\Services;

use App\Models\Archive;
use App\Models\Channel;
use App\Models\Song;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use App\Services\AutoLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutoLinkServiceTest extends TestCase
{
    use RefreshDatabase;

    protected AutoLinkService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AutoLinkService::class);
    }

    /**
     * 未紐付けタイムスタンプがない場合のテスト
     */
    public function test_auto_link_with_no_unlinked_timestamps(): void
    {
        $result = $this->service->autoLinkUnlinkedTimestamps(10);

        $this->assertEquals(0, $result['processed']);
        $this->assertEquals(0, $result['linked']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEquals(0, $result['skipped']);
    }

    /**
     * Spotify検索で結果が見つかり、新規楽曲マスタを作成するテスト
     */
    public function test_auto_link_creates_new_song_and_mapping(): void
    {
        // テストデータ作成
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);
        TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'Test Song',
            'is_display' => 1,
        ]);

        // Spotify APIをモック
        Http::fake([
            'https://accounts.spotify.com/api/token' => Http::response([
                'access_token' => 'test_token',
            ], 200),
            'https://api.spotify.com/v1/search*' => Http::response([
                'tracks' => [
                    'items' => [
                        [
                            'id' => 'spotify_track_123',
                            'name' => 'Test Song',
                            'artists' => [['name' => 'Test Artist']],
                            'album' => ['name' => 'Test Album'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        config(['services.spotify.client_id' => 'test_id']);
        config(['services.spotify.client_secret' => 'test_secret']);

        $result = $this->service->autoLinkUnlinkedTimestamps(10);

        $this->assertEquals(1, $result['processed']);
        $this->assertEquals(1, $result['linked']);
        $this->assertEquals(0, $result['failed']);

        // 楽曲マスタが作成されたことを確認
        $this->assertDatabaseHas('songs', [
            'title' => 'Test Song',
            'artist' => 'Test Artist',
            'spotify_track_id' => 'spotify_track_123',
        ]);

        // マッピングが作成されたことを確認
        $this->assertDatabaseHas('timestamp_song_mappings', [
            'is_manual' => false,
            'confidence' => 0.8,
        ]);
    }

    /**
     * 既存のSpotify Track IDがある場合、既存楽曲を使用するテスト
     */
    public function test_auto_link_uses_existing_song_by_spotify_id(): void
    {
        // 既存楽曲を作成
        $existingSong = Song::factory()->create([
            'title' => 'Existing Song',
            'artist' => 'Existing Artist',
            'spotify_track_id' => 'existing_spotify_id',
        ]);

        // テストデータ作成
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);
        TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'New Timestamp Text',
            'is_display' => 1,
        ]);

        // Spotify APIをモック（既存のspotify_track_idを返す）
        Http::fake([
            'https://accounts.spotify.com/api/token' => Http::response([
                'access_token' => 'test_token',
            ], 200),
            'https://api.spotify.com/v1/search*' => Http::response([
                'tracks' => [
                    'items' => [
                        [
                            'id' => 'existing_spotify_id',
                            'name' => 'Existing Song',
                            'artists' => [['name' => 'Existing Artist']],
                        ],
                    ],
                ],
            ], 200),
        ]);

        config(['services.spotify.client_id' => 'test_id']);
        config(['services.spotify.client_secret' => 'test_secret']);

        $result = $this->service->autoLinkUnlinkedTimestamps(10);

        $this->assertEquals(1, $result['linked']);

        // 新しい楽曲マスタが作成されていないことを確認
        $this->assertDatabaseCount('songs', 1);

        // 既存楽曲にマッピングされたことを確認
        $this->assertDatabaseHas('timestamp_song_mappings', [
            'song_id' => $existingSong->id,
            'is_manual' => false,
        ]);
    }

    /**
     * Spotify検索で結果がない場合のテスト
     */
    public function test_auto_link_no_spotify_results(): void
    {
        // テストデータ作成
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);
        TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'Unknown Song',
            'is_display' => 1,
        ]);

        // Spotify APIをモック（空の結果を返す）
        Http::fake([
            'https://accounts.spotify.com/api/token' => Http::response([
                'access_token' => 'test_token',
            ], 200),
            'https://api.spotify.com/v1/search*' => Http::response([
                'tracks' => [
                    'items' => [],
                ],
            ], 200),
        ]);

        config(['services.spotify.client_id' => 'test_id']);
        config(['services.spotify.client_secret' => 'test_secret']);

        $result = $this->service->autoLinkUnlinkedTimestamps(10);

        $this->assertEquals(1, $result['processed']);
        $this->assertEquals(0, $result['linked']);
        $this->assertEquals(1, $result['failed']);
    }

    /**
     * 紐付け済みタイムスタンプは処理対象外のテスト
     */
    public function test_auto_link_skips_already_linked_timestamps(): void
    {
        // テストデータ作成
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);

        // 紐付け済みタイムスタンプ
        $linkedTs = TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'Linked Song',
            'is_display' => 1,
        ]);

        $song = Song::factory()->create();
        TimestampSongMapping::factory()
            ->withSong($song)
            ->withText($linkedTs->text)
            ->create();

        // Spotify APIをモック
        Http::fake([
            'https://accounts.spotify.com/api/token' => Http::response([
                'access_token' => 'test_token',
            ], 200),
        ]);

        config(['services.spotify.client_id' => 'test_id']);
        config(['services.spotify.client_secret' => 'test_secret']);

        $result = $this->service->autoLinkUnlinkedTimestamps(10);

        // 処理対象がないことを確認
        $this->assertEquals(0, $result['processed']);
    }

    /**
     * is_display=0のタイムスタンプは処理対象外のテスト
     */
    public function test_auto_link_skips_hidden_timestamps(): void
    {
        // テストデータ作成
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);

        // 非表示タイムスタンプ
        TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'Hidden Song',
            'is_display' => 0,
        ]);

        $result = $this->service->autoLinkUnlinkedTimestamps(10);

        $this->assertEquals(0, $result['processed']);
    }

    /**
     * 処理件数上限のテスト
     */
    public function test_auto_link_respects_limit(): void
    {
        // テストデータ作成
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);

        // 5件のタイムスタンプを作成
        for ($i = 1; $i <= 5; $i++) {
            TsItem::factory()->create([
                'video_id' => $archive->video_id,
                'text' => "Song {$i}",
                'is_display' => 1,
            ]);
        }

        // Spotify APIをモック
        Http::fake([
            'https://accounts.spotify.com/api/token' => Http::response([
                'access_token' => 'test_token',
            ], 200),
            'https://api.spotify.com/v1/search*' => Http::response([
                'tracks' => [
                    'items' => [
                        [
                            'id' => 'spotify_track_'.uniqid(),
                            'name' => 'Song',
                            'artists' => [['name' => 'Artist']],
                        ],
                    ],
                ],
            ], 200),
        ]);

        config(['services.spotify.client_id' => 'test_id']);
        config(['services.spotify.client_secret' => 'test_secret']);

        // 2件だけ処理
        $result = $this->service->autoLinkUnlinkedTimestamps(2);

        $this->assertEquals(2, $result['processed']);
    }

    /**
     * 類似曲がある場合、その曲に紐付けるテスト
     */
    public function test_auto_link_uses_existing_song_by_similarity(): void
    {
        // 既存楽曲を作成（Spotify Track IDは異なるが、タイトル・アーティストが類似）
        $existingSong = Song::factory()->create([
            'title' => 'Test Song',
            'artist' => 'Test Artist',
            'spotify_track_id' => 'different_spotify_id',
        ]);

        // テストデータ作成
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);
        TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'Some Search Text',
            'is_display' => 1,
        ]);

        // Spotify APIをモック（既存楽曲と類似した結果を返す）
        Http::fake([
            'https://accounts.spotify.com/api/token' => Http::response([
                'access_token' => 'test_token',
            ], 200),
            'https://api.spotify.com/v1/search*' => Http::response([
                'tracks' => [
                    'items' => [
                        [
                            'id' => 'new_spotify_id',
                            'name' => 'Test Song',
                            'artists' => [['name' => 'Test Artist']],
                        ],
                    ],
                ],
            ], 200),
        ]);

        config(['services.spotify.client_id' => 'test_id']);
        config(['services.spotify.client_secret' => 'test_secret']);

        $result = $this->service->autoLinkUnlinkedTimestamps(10);

        $this->assertEquals(1, $result['processed']);
        $this->assertEquals(1, $result['linked']);
        $this->assertEquals(0, $result['skipped']);

        // 新しい楽曲マスタが作成されていないことを確認
        $this->assertDatabaseCount('songs', 1);

        // 既存の類似曲にマッピングされたことを確認
        $this->assertDatabaseHas('timestamp_song_mappings', [
            'song_id' => $existingSong->id,
            'is_manual' => false,
        ]);
    }

    /**
     * 進捗コールバックが呼び出されるテスト
     */
    public function test_auto_link_calls_progress_callback(): void
    {
        // テストデータ作成
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);
        TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'Test Song',
            'is_display' => 1,
        ]);

        // Spotify APIをモック
        Http::fake([
            'https://accounts.spotify.com/api/token' => Http::response([
                'access_token' => 'test_token',
            ], 200),
            'https://api.spotify.com/v1/search*' => Http::response([
                'tracks' => [
                    'items' => [
                        [
                            'id' => 'spotify_track_123',
                            'name' => 'Test Song',
                            'artists' => [['name' => 'Test Artist']],
                        ],
                    ],
                ],
            ], 200),
        ]);

        config(['services.spotify.client_id' => 'test_id']);
        config(['services.spotify.client_secret' => 'test_secret']);

        $messages = [];
        $result = $this->service->autoLinkUnlinkedTimestamps(10, function ($message) use (&$messages) {
            $messages[] = $message;
        });

        // コールバックが呼び出されたことを確認
        $this->assertNotEmpty($messages);
        $this->assertStringContainsString('処理します', $messages[0]);
    }

    /**
     * 類似度が保留閾値以上かつ自動紐付け閾値未満の場合、保留になるテスト
     */
    public function test_auto_link_creates_pending_mapping_for_medium_similarity(): void
    {
        // 設定: 自動紐付け閾値0.95、保留閾値0.85
        config(['songs.auto_link.similarity_threshold' => 0.95]);
        config(['songs.auto_link.pending_threshold' => 0.85]);

        // 既存楽曲を作成（類似だが完全一致ではない - 約91%の類似度になるように調整）
        // "Test Song" vs "Test Song X" → タイトル類似度約82%
        // "Test Artist" vs "Test Artist" → アーティスト類似度100%
        // 平均: 約91% → 保留範囲（85%以上95%未満）
        $existingSong = Song::factory()->create([
            'title' => 'Test Song',
            'artist' => 'Test Artist',
            'spotify_track_id' => 'different_spotify_id',
        ]);

        // テストデータ作成
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);
        TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'Some Search Text',
            'is_display' => 1,
        ]);

        // Spotify APIをモック（類似した結果を返す：約91%程度の類似度を狙う）
        Http::fake([
            'https://accounts.spotify.com/api/token' => Http::response([
                'access_token' => 'test_token',
            ], 200),
            'https://api.spotify.com/v1/search*' => Http::response([
                'tracks' => [
                    'items' => [
                        [
                            'id' => 'new_spotify_id',
                            'name' => 'Test Song X',
                            'artists' => [['name' => 'Test Artist']],
                        ],
                    ],
                ],
            ], 200),
        ]);

        config(['services.spotify.client_id' => 'test_id']);
        config(['services.spotify.client_secret' => 'test_secret']);

        $result = $this->service->autoLinkUnlinkedTimestamps(10);

        $this->assertEquals(1, $result['processed']);
        $this->assertEquals(1, $result['pending']);
        $this->assertEquals(0, $result['linked']);

        // 保留状態でマッピングが作成されたことを確認
        $this->assertDatabaseHas('timestamp_song_mappings', [
            'song_id' => $existingSong->id,
            'status' => TimestampSongMapping::STATUS_PENDING,
            'is_manual' => false,
        ]);
    }

    /**
     * 類似度が自動紐付け閾値以上の場合、linkedになるテスト
     */
    public function test_auto_link_creates_linked_mapping_for_high_similarity(): void
    {
        // 設定: 自動紐付け閾値0.95、保留閾値0.85
        config(['songs.auto_link.similarity_threshold' => 0.95]);
        config(['songs.auto_link.pending_threshold' => 0.85]);

        // 既存楽曲を作成（完全に同じタイトル・アーティスト）
        $existingSong = Song::factory()->create([
            'title' => 'Test Song',
            'artist' => 'Test Artist',
            'spotify_track_id' => 'different_spotify_id',
        ]);

        // テストデータ作成
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);
        TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'Some Search Text',
            'is_display' => 1,
        ]);

        // Spotify APIをモック（完全一致の結果を返す）
        Http::fake([
            'https://accounts.spotify.com/api/token' => Http::response([
                'access_token' => 'test_token',
            ], 200),
            'https://api.spotify.com/v1/search*' => Http::response([
                'tracks' => [
                    'items' => [
                        [
                            'id' => 'new_spotify_id',
                            'name' => 'Test Song',
                            'artists' => [['name' => 'Test Artist']],
                        ],
                    ],
                ],
            ], 200),
        ]);

        config(['services.spotify.client_id' => 'test_id']);
        config(['services.spotify.client_secret' => 'test_secret']);

        $result = $this->service->autoLinkUnlinkedTimestamps(10);

        $this->assertEquals(1, $result['processed']);
        $this->assertEquals(1, $result['linked']);
        $this->assertEquals(0, $result['pending']);

        // linked状態でマッピングが作成されたことを確認
        $this->assertDatabaseHas('timestamp_song_mappings', [
            'song_id' => $existingSong->id,
            'status' => TimestampSongMapping::STATUS_LINKED,
            'is_manual' => false,
        ]);
    }

    /**
     * 結果配列にpendingが含まれることを確認するテスト
     */
    public function test_auto_link_result_includes_pending_count(): void
    {
        $result = $this->service->autoLinkUnlinkedTimestamps(10);

        $this->assertArrayHasKey('pending', $result);
        $this->assertEquals(0, $result['pending']);
    }
}
