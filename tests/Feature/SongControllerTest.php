<?php

namespace Tests\Feature;

use App\Models\Archive;
use App\Models\Channel;
use App\Models\Song;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SongControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['email_verified_at' => now()]);
    }

    /**
     * タイムスタンプ一覧取得のテスト（基本）
     */
    public function test_fetch_timestamps_basic(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);
        TsItem::factory()->count(3)->create([
            'video_id' => $archive->video_id,
            'text' => 'Test Song',
            'is_display' => 1,
        ]);

        $response = $this->actingAs($this->user)->getJson(route('songs.fetchTimestamps'));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data',
            'current_page',
            'last_page',
            'per_page',
            'total',
            'from',
            'to',
        ]);
        $this->assertEquals(3, $response->json('total'));
    }

    /**
     * タイムスタンプ一覧取得のテスト（ページネーション）
     */
    public function test_fetch_timestamps_pagination(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);
        TsItem::factory()->count(25)->create([
            'video_id' => $archive->video_id,
            'is_display' => 1,
        ]);

        $response = $this->actingAs($this->user)->getJson(route('songs.fetchTimestamps', [
            'per_page' => 10,
            'page' => 2,
        ]));

        $response->assertStatus(200);
        $this->assertEquals(2, $response->json('current_page'));
        $this->assertEquals(10, $response->json('per_page'));
        $this->assertEquals(25, $response->json('total'));
        $this->assertEquals(3, $response->json('last_page'));
        $this->assertCount(10, $response->json('data'));
    }

    /**
     * タイムスタンプ一覧取得のテスト（検索フィルター）
     */
    public function test_fetch_timestamps_with_search(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);
        TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'Test Song A',
            'is_display' => 1,
        ]);
        TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'Test Song B',
            'is_display' => 1,
        ]);
        TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'Different Track',
            'is_display' => 1,
        ]);

        $response = $this->actingAs($this->user)->getJson(route('songs.fetchTimestamps', [
            'search' => 'Song',
        ]));

        $response->assertStatus(200);
        $this->assertEquals(2, $response->json('total'));
    }

    /**
     * タイムスタンプ一覧取得のテスト（未連携フィルター）
     */
    public function test_fetch_timestamps_with_unlinked_only_filter(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);

        // 未連携タイムスタンプ
        $unlinked = TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'Unlinked Song',
            'is_display' => 1,
        ]);

        // 連携済みタイムスタンプ
        $linked = TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'Linked Song',
            'is_display' => 1,
        ]);

        $song = Song::factory()->create();
        TimestampSongMapping::factory()
            ->withSong($song)
            ->withText($linked->text)
            ->create();

        $response = $this->actingAs($this->user)->getJson(route('songs.fetchTimestamps', [
            'unlinked_only' => true,
        ]));

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('total'));
    }

    /**
     * タイムスタンプ一覧取得のテスト（マッピング情報付き）
     */
    public function test_fetch_timestamps_with_mapping_info(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);

        $tsItem = TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'Test Song',
            'is_display' => 1,
        ]);

        $song = Song::factory()->create([
            'title' => 'Test Song',
            'artist' => 'Test Artist',
        ]);

        TimestampSongMapping::factory()
            ->withSong($song)
            ->withText($tsItem->text)
            ->create();

        $response = $this->actingAs($this->user)->getJson(route('songs.fetchTimestamps'));

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotNull($data[0]['mapping']);
        $this->assertNotNull($data[0]['song']);
        $this->assertEquals($song->id, $data[0]['song']['id']);
    }

    /**
     * タイムスタンプ一覧取得のテスト（is_display=0を除外）
     */
    public function test_fetch_timestamps_excludes_hidden_items(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);

        TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'Visible Song',
            'is_display' => 1,
        ]);

        TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'Hidden Song',
            'is_display' => 0,
        ]);

        $response = $this->actingAs($this->user)->getJson(route('songs.fetchTimestamps'));

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('total'));
    }

    /**
     * 楽曲マスタ一覧取得のテスト（基本）
     */
    public function test_fetch_songs_basic(): void
    {
        Song::factory()->count(3)->create();

        $response = $this->actingAs($this->user)->getJson(route('songs.fetchSongs'));

        $response->assertStatus(200);
        $this->assertCount(3, $response->json());
    }

    /**
     * 楽曲マスタ一覧取得のテスト（検索）
     */
    public function test_fetch_songs_with_search(): void
    {
        Song::factory()->create(['title' => 'Test Song A', 'artist' => 'Artist X']);
        Song::factory()->create(['title' => 'Test Song B', 'artist' => 'Artist Y']);
        Song::factory()->create(['title' => 'Different Track', 'artist' => 'Artist Z']);

        $response = $this->actingAs($this->user)->getJson(route('songs.fetchSongs', [
            'search' => 'Song',
        ]));

        $response->assertStatus(200);
        $this->assertCount(2, $response->json());
    }

    /**
     * 楽曲マスタ一覧取得のテスト（アーティスト名で検索）
     */
    public function test_fetch_songs_search_by_artist(): void
    {
        Song::factory()->create(['title' => 'Song 1', 'artist' => 'Beatles']);
        Song::factory()->create(['title' => 'Song 2', 'artist' => 'Rolling Stones']);
        Song::factory()->create(['title' => 'Song 3', 'artist' => 'Beatles Tribute']);

        $response = $this->actingAs($this->user)->getJson(route('songs.fetchSongs', [
            'search' => 'Beatles',
        ]));

        $response->assertStatus(200);
        $this->assertCount(2, $response->json());
    }

    /**
     * 楽曲マスタ登録のテスト（新規作成）
     */
    public function test_store_song_creates_new(): void
    {
        $response = $this->actingAs($this->user)->postJson(route('songs.storeSong'), [
            'title' => 'New Song',
            'artist' => 'New Artist',
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'status' => 'created',
            'message' => '新規の楽曲マスタを作成しました。',
        ]);

        $this->assertDatabaseHas('songs', [
            'title' => 'New Song',
            'artist' => 'New Artist',
        ]);
    }

    /**
     * 楽曲マスタ登録のテスト（Spotify情報付き）
     */
    public function test_store_song_with_spotify_data(): void
    {
        $response = $this->actingAs($this->user)->postJson(route('songs.storeSong'), [
            'title' => 'Spotify Song',
            'artist' => 'Spotify Artist',
            'spotify_track_id' => '1234567890abcdefghij12', // 22 characters
            'spotify_data' => [
                'album' => [
                    'name' => 'Test Album',
                    'release_date' => '2024-01-01',
                ],
                'duration_ms' => 180000,
            ],
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('songs', [
            'title' => 'Spotify Song',
            'artist' => 'Spotify Artist',
            'spotify_track_id' => '1234567890abcdefghij12',
        ]);
    }

    /**
     * 楽曲マスタ登録のテスト（Spotify IDで完全一致）
     */
    public function test_store_song_exact_match_by_spotify_id(): void
    {
        $existingSong = Song::factory()->create([
            'title' => 'Existing Song',
            'artist' => 'Existing Artist',
            'spotify_track_id' => 'existingspotifyid123',
        ]);

        $response = $this->actingAs($this->user)->postJson(route('songs.storeSong'), [
            'title' => 'New Song Name',
            'artist' => 'New Artist Name',
            'spotify_track_id' => 'existingspotifyid123',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'exact_match',
            'song' => ['id' => $existingSong->id],
            'message' => '既に登録されている楽曲マスタが見つかりました。',
        ]);
    }

    /**
     * 楽曲マスタ登録のテスト（正規化後のタイトル・アーティストで完全一致）
     */
    public function test_store_song_exact_match_by_normalized_text(): void
    {
        $existingSong = Song::factory()->create([
            'title' => 'Test Song',
            'artist' => 'Test Artist',
        ]);

        // 空白や大文字小文字が異なる入力
        $response = $this->actingAs($this->user)->postJson(route('songs.storeSong'), [
            'title' => '  test  song  ',
            'artist' => '  TEST  ARTIST  ',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'exact_match',
            'song' => ['id' => $existingSong->id],
        ]);
    }

    /**
     * 楽曲マスタ登録のテスト（類似曲検出）
     */
    public function test_store_song_detects_similar_songs(): void
    {
        Song::factory()->create([
            'title' => 'Yesterday',
            'artist' => 'The Beatles',
        ]);

        Config::set('songs.similarity_threshold', 0.75);

        $response = $this->actingAs($this->user)->postJson(route('songs.storeSong'), [
            'title' => 'Yesterday!',
            'artist' => 'Beatles',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'similar_found',
            'message' => '類似する楽曲マスタが見つかりました。既存のマスタを使用するか、新規登録するか選択してください。',
        ]);
        $this->assertArrayHasKey('similar_songs', $response->json());
    }

    /**
     * 楽曲マスタ登録のテスト（force_createフラグで強制新規作成）
     */
    public function test_store_song_force_create(): void
    {
        Song::factory()->create([
            'title' => 'Yesterday',
            'artist' => 'The Beatles',
        ]);

        $response = $this->actingAs($this->user)->postJson(route('songs.storeSong'), [
            'title' => 'Yesterday!',
            'artist' => 'Beatles',
            'force_create' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'status' => 'created',
        ]);

        $this->assertDatabaseCount('songs', 2);
    }

    /**
     * 楽曲マスタ登録のテスト（use_existing_idで既存曲使用）
     */
    public function test_store_song_use_existing_id(): void
    {
        $existingSong = Song::factory()->create([
            'title' => 'Existing Song',
            'artist' => 'Existing Artist',
        ]);

        $response = $this->actingAs($this->user)->postJson(route('songs.storeSong'), [
            'title' => 'Different Title',
            'artist' => 'Different Artist',
            'use_existing_id' => $existingSong->id,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'existing_used',
            'song' => ['id' => $existingSong->id],
            'message' => '既存の楽曲マスタを使用します。',
        ]);

        $this->assertDatabaseCount('songs', 1);
    }

    /**
     * 楽曲マスタ登録のテスト（バリデーションエラー）
     */
    public function test_store_song_validation_errors(): void
    {
        $response = $this->actingAs($this->user)->postJson(route('songs.storeSong'), [
            'title' => '', // 必須
            'artist' => '', // 必須
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['title', 'artist']);
    }

    /**
     * 楽曲マスタ登録のテスト（Spotify Track ID形式バリデーション）
     */
    public function test_store_song_spotify_track_id_validation(): void
    {
        $response = $this->actingAs($this->user)->postJson(route('songs.storeSong'), [
            'title' => 'Test Song',
            'artist' => 'Test Artist',
            'spotify_track_id' => 'invalid-id-with-special@chars',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['spotify_track_id']);
    }

    /**
     * タイムスタンプと楽曲を紐づけるテスト（新規作成）
     */
    public function test_link_timestamp_creates_new_mapping(): void
    {
        $song = Song::factory()->create();
        $normalizedText = 'test song';

        $response = $this->actingAs($this->user)->postJson(route('songs.linkTimestamp'), [
            'normalized_text' => $normalizedText,
            'song_id' => $song->id,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'タイムスタンプと楽曲を紐づけました。',
        ]);

        $this->assertDatabaseHas('timestamp_song_mappings', [
            'normalized_text' => $normalizedText,
            'song_id' => $song->id,
            'is_manual' => 1,
            'confidence' => 1.0,
        ]);
    }

    /**
     * タイムスタンプと楽曲を紐づけるテスト（既存レコード更新）
     */
    public function test_link_timestamp_updates_existing_mapping(): void
    {
        $song1 = Song::factory()->create();
        $song2 = Song::factory()->create();

        $mapping = TimestampSongMapping::factory()
            ->withSong($song1)
            ->withText('test song')
            ->create();

        $response = $this->actingAs($this->user)->postJson(route('songs.linkTimestamp'), [
            'normalized_text' => $mapping->normalized_text,
            'song_id' => $song2->id,
        ]);

        $response->assertStatus(200);

        $mapping->refresh();
        $this->assertEquals($song2->id, $mapping->song_id);
        $this->assertTrue($mapping->is_manual);
    }

    /**
     * タイムスタンプと楽曲を紐づけるテスト（バリデーションエラー）
     */
    public function test_link_timestamp_validation_errors(): void
    {
        $response = $this->actingAs($this->user)->postJson(route('songs.linkTimestamp'), [
            'normalized_text' => '',
            'song_id' => 'nonexistent_id',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['normalized_text', 'song_id']);
    }

    /**
     * 「楽曲ではない」マークのテスト（新規作成）
     */
    public function test_mark_as_not_song_creates_new_mapping(): void
    {
        $normalizedText = 'not a song';

        $response = $this->actingAs($this->user)->postJson(route('songs.markAsNotSong'), [
            'normalized_text' => $normalizedText,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'message' => '楽曲ではないとマークしました。',
        ]);

        $this->assertDatabaseHas('timestamp_song_mappings', [
            'normalized_text' => $normalizedText,
            'is_not_song' => 1,
            'song_id' => null,
        ]);
    }

    /**
     * 「楽曲ではない」マークのテスト（既存レコード更新）
     */
    public function test_mark_as_not_song_updates_existing_mapping(): void
    {
        $song = Song::factory()->create();
        $mapping = TimestampSongMapping::factory()
            ->withSong($song)
            ->withText('test song')
            ->create();

        $response = $this->actingAs($this->user)->postJson(route('songs.markAsNotSong'), [
            'normalized_text' => $mapping->normalized_text,
        ]);

        $response->assertStatus(200);

        $mapping->refresh();
        $this->assertTrue($mapping->is_not_song);
        $this->assertNull($mapping->song_id);
    }

    /**
     * 「楽曲ではない」マーク解除のテスト
     */
    public function test_unmark_as_not_song(): void
    {
        $mapping = TimestampSongMapping::factory()
            ->notSong()
            ->withText('not a song')
            ->create();

        $response = $this->actingAs($this->user)->postJson(route('songs.unmarkAsNotSong'), [
            'normalized_text' => $mapping->normalized_text,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'message' => '「楽曲ではない」マークを解除しました。',
        ]);

        $this->assertDatabaseMissing('timestamp_song_mappings', [
            'id' => $mapping->id,
        ]);
    }

    /**
     * 「楽曲ではない」でないマッピングを解除しようとしても削除されないテスト
     */
    public function test_unmark_as_not_song_does_not_delete_normal_mapping(): void
    {
        $song = Song::factory()->create();
        $mapping = TimestampSongMapping::factory()
            ->withSong($song)
            ->withText('test song')
            ->create();

        $response = $this->actingAs($this->user)->postJson(route('songs.unmarkAsNotSong'), [
            'normalized_text' => $mapping->normalized_text,
        ]);

        $response->assertStatus(200);

        // is_not_song=false のマッピングは削除されない
        $this->assertDatabaseHas('timestamp_song_mappings', [
            'id' => $mapping->id,
        ]);
    }

    /**
     * マッピング解除のテスト
     */
    public function test_unlink_timestamp_deletes_mapping(): void
    {
        $song = Song::factory()->create();
        $mapping = TimestampSongMapping::factory()
            ->withSong($song)
            ->withText('test song')
            ->create();

        $response = $this->actingAs($this->user)
            ->deleteJson('/api/songs/unlink', [
                'normalized_text' => $mapping->normalized_text,
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'マッピングを解除しました。',
        ]);

        $this->assertDatabaseMissing('timestamp_song_mappings', [
            'id' => $mapping->id,
        ]);
    }

    /**
     * あいまい検索のテスト（マッチあり）
     */
    public function test_fuzzy_search_finds_match(): void
    {
        $song = Song::factory()->create([
            'title' => 'Yesterday',
            'artist' => 'The Beatles',
        ]);

        TimestampSongMapping::factory()
            ->withSong($song)
            ->withText('yesterday beatles')
            ->create();

        // 微妙に異なるテキストで検索（大文字・全角・空白が異なる）
        $response = $this->actingAs($this->user)->getJson(route('songs.fuzzySearch', [
            'text' => 'YESTERDAY　　BEATLES',  // 全角スペース、大文字
            'threshold' => 0.7,
        ]));

        $response->assertStatus(200);
        $response->assertJson([
            'found' => true,
        ]);
        $this->assertNotNull($response->json('mapping'));
        $this->assertEquals($song->id, $response->json('mapping.song.id'));
    }

    /**
     * あいまい検索のテスト（マッチなし）
     */
    public function test_fuzzy_search_no_match(): void
    {
        $song = Song::factory()->create();
        TimestampSongMapping::factory()
            ->withSong($song)
            ->withText('yesterday beatles')
            ->create();

        $response = $this->actingAs($this->user)->getJson(route('songs.fuzzySearch', [
            'text' => 'completely different text',
            'threshold' => 0.7,
        ]));

        $response->assertStatus(200);
        $response->assertJson([
            'found' => false,
        ]);
    }

    /**
     * Spotify検索のテスト（成功）
     */
    public function test_search_spotify_success(): void
    {
        Config::set('services.spotify.client_id', 'test_client_id');
        Config::set('services.spotify.client_secret', 'test_client_secret');

        Http::fake([
            'https://accounts.spotify.com/api/token' => Http::response([
                'access_token' => 'test_token',
            ], 200),
            'https://api.spotify.com/v1/search*' => Http::response([
                'tracks' => [
                    'items' => [
                        [
                            'id' => 'track1',
                            'name' => 'Song A',
                            'artists' => [['name' => 'Artist A']],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)->getJson(route('songs.searchSpotify', [
            'query' => 'test query',
            'limit' => 5,
        ]));

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data);
    }

    /**
     * Spotify検索のテスト（認証情報なし）
     */
    public function test_search_spotify_missing_credentials(): void
    {
        Config::set('services.spotify.client_id', null);
        Config::set('services.spotify.client_secret', null);

        $response = $this->actingAs($this->user)->getJson(route('songs.searchSpotify', [
            'query' => 'test query',
        ]));

        $response->assertStatus(500);
        $response->assertJson([
            'error' => 'Spotify API credentials are not configured.',
        ]);
    }

    /**
     * 楽曲マスタ削除のテスト（基本）
     */
    public function test_delete_song_removes_song_and_mappings(): void
    {
        $song = Song::factory()->create();
        TimestampSongMapping::factory()
            ->withSong($song)
            ->count(3)
            ->create();

        $response = $this->actingAs($this->user)->deleteJson(route('songs.deleteSong', $song->id));

        $response->assertStatus(200);
        $response->assertJson([
            'message' => '楽曲マスタを削除しました。',
        ]);

        $this->assertDatabaseMissing('songs', ['id' => $song->id]);
        $this->assertDatabaseMissing('timestamp_song_mappings', ['song_id' => $song->id]);
    }

    /**
     * 楽曲マスタ削除のテスト（存在しないID）
     */
    public function test_delete_song_not_found(): void
    {
        $response = $this->actingAs($this->user)->deleteJson(route('songs.deleteSong', 'nonexistent_id'));

        $response->assertStatus(404);
    }

    /**
     * 複雑なシナリオ: 全体の流れをテスト
     */
    public function test_complete_workflow(): void
    {
        // 1. 楽曲マスタを作成
        $response = $this->actingAs($this->user)->postJson(route('songs.storeSong'), [
            'title' => 'Bohemian Rhapsody',
            'artist' => 'Queen',
        ]);
        $response->assertStatus(201);
        $songId = $response->json('song.id');

        // 2. タイムスタンプと紐づける
        $normalizedText = 'bohemian rhapsody queen';
        $response = $this->actingAs($this->user)->postJson(route('songs.linkTimestamp'), [
            'normalized_text' => $normalizedText,
            'song_id' => $songId,
        ]);
        $response->assertStatus(200);

        // 3. マッピングが作成されたことを確認
        $this->assertDatabaseHas('timestamp_song_mappings', [
            'normalized_text' => $normalizedText,
            'song_id' => $songId,
        ]);

        // 4. 楽曲を削除すると、マッピングも削除される
        $response = $this->actingAs($this->user)->deleteJson(route('songs.deleteSong', $songId));
        $response->assertStatus(200);

        $this->assertDatabaseMissing('songs', ['id' => $songId]);
        $this->assertDatabaseMissing('timestamp_song_mappings', ['song_id' => $songId]);
    }

    /**
     * 未認証アクセスのテスト
     */
    public function test_unauthenticated_access_is_forbidden(): void
    {
        // GETエンドポイント
        $this->getJson(route('songs.fetchTimestamps'))->assertStatus(401);
        $this->getJson(route('songs.fetchSongs'))->assertStatus(401);
        $this->getJson(route('songs.fuzzySearch', ['text' => 'test']))->assertStatus(401);
        $this->getJson(route('songs.searchSpotify', ['query' => 'test']))->assertStatus(401);

        // POSTエンドポイント
        $this->postJson(route('songs.storeSong'), [
            'title' => 'Test',
            'artist' => 'Test',
        ])->assertStatus(401);

        $this->postJson(route('songs.linkTimestamp'), [
            'normalized_text' => 'test',
            'song_id' => 'test-id',
        ])->assertStatus(401);

        $this->postJson(route('songs.markAsNotSong'), [
            'normalized_text' => 'test',
        ])->assertStatus(401);

        // DELETEエンドポイント
        $this->deleteJson(route('songs.unlinkTimestamp'), [
            'normalized_text' => 'test',
        ])->assertStatus(401);

        $this->deleteJson(route('songs.deleteSong', 'test-id'))->assertStatus(401);
    }
}
