<?php

namespace Tests\Feature;

use App\Models\Archive;
use App\Models\Channel;
use App\Models\Song;
use App\Models\SongTag;
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
        $this->user = User::factory()->create([
            'email_verified_at' => now(),
            'role' => User::ROLE_SUPER_ADMIN,
        ]);
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
     * タイムスタンプ一覧取得のテスト（フィルター: unlinked）
     */
    public function test_fetch_timestamps_with_unlinked_filter(): void
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
            'filter' => 'unlinked',
        ]));

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('total'));
    }

    /**
     * タイムスタンプ一覧取得のテスト（フィルター: linked）
     */
    public function test_fetch_timestamps_with_linked_filter(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);

        // 未連携タイムスタンプ
        TsItem::factory()->create([
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
            'filter' => 'linked',
        ]));

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('total'));
    }

    /**
     * タイムスタンプ一覧取得のテスト（フィルター: not_song）
     */
    public function test_fetch_timestamps_with_not_song_filter(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);

        // 未連携タイムスタンプ
        TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'Unlinked Song',
            'is_display' => 1,
        ]);

        // 「楽曲ではない」タイムスタンプ
        $notSong = TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'Not A Song',
            'is_display' => 1,
        ]);

        TimestampSongMapping::factory()
            ->withText($notSong->text)
            ->notSong()
            ->create();

        $response = $this->actingAs($this->user)->getJson(route('songs.fetchTimestamps', [
            'filter' => 'not_song',
        ]));

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('total'));
    }

    /**
     * タイムスタンプ一覧取得のテスト（フィルター: auto_linked）
     */
    public function test_fetch_timestamps_with_auto_linked_filter(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);

        // 自動紐付けタイムスタンプ
        $autoLinked = TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'Auto Linked Song',
            'is_display' => 1,
        ]);

        $song1 = Song::factory()->create();
        TimestampSongMapping::factory()
            ->withSong($song1)
            ->withText($autoLinked->text)
            ->autoLinked()
            ->create();

        // 手動紐付けタイムスタンプ
        $manualLinked = TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'Manual Linked Song',
            'is_display' => 1,
        ]);

        $song2 = Song::factory()->create();
        TimestampSongMapping::factory()
            ->withSong($song2)
            ->withText($manualLinked->text)
            ->manual()
            ->create();

        // 未紐付けタイムスタンプ
        TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'Unlinked Song',
            'is_display' => 1,
        ]);

        $response = $this->actingAs($this->user)->getJson(route('songs.fetchTimestamps', [
            'filter' => 'auto_linked',
        ]));

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('total'));
        $this->assertEquals('Auto Linked Song', $response->json('data.0.text'));
    }

    /**
     * タイムスタンプ一覧取得のテスト（is_manual情報が含まれる）
     */
    public function test_fetch_timestamps_includes_is_manual_info(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);

        // 自動紐付けタイムスタンプ
        $autoLinked = TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'Auto Linked Song',
            'is_display' => 1,
        ]);

        $song = Song::factory()->create();
        TimestampSongMapping::factory()
            ->withSong($song)
            ->withText($autoLinked->text)
            ->autoLinked()
            ->create();

        $response = $this->actingAs($this->user)->getJson(route('songs.fetchTimestamps'));

        $response->assertStatus(200);
        $this->assertFalse($response->json('data.0.is_manual'));
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
        $response->assertJsonStructure(['data', 'total']);
        $this->assertCount(3, $response->json('data'));
        $this->assertEquals(3, $response->json('total'));
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
        $response->assertJsonStructure(['data', 'total']);
        $this->assertCount(2, $response->json('data'));
        $this->assertEquals(2, $response->json('total'));
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
        $response->assertJsonStructure(['data', 'total']);
        $this->assertCount(2, $response->json('data'));
        $this->assertEquals(2, $response->json('total'));
    }

    /**
     * 楽曲マスタ一覧取得のテスト（review_statusフィルタ: needs_review）
     */
    public function test_fetch_songs_filter_by_needs_review(): void
    {
        Song::factory()->create(['title' => 'Safe Song', 'artist' => 'A', 'review_status' => 'safe']);
        Song::factory()->create(['title' => 'Review Song', 'artist' => 'B', 'review_status' => 'needs_review']);
        Song::factory()->create(['title' => 'No Status', 'artist' => 'C', 'review_status' => null]);

        $response = $this->actingAs($this->user)->getJson(route('songs.fetchSongs', ['review_status' => 'needs_review']));

        $response->assertOk();
        $this->assertEquals(1, $response->json('total'));
        $this->assertEquals('Review Song', $response->json('data.0.title'));
    }

    /**
     * 楽曲マスタ一覧取得のテスト（review_status=nullはどちらのフィルタにも含まれない）
     */
    public function test_fetch_songs_null_review_status_excluded_from_both_filters(): void
    {
        Song::factory()->create(['title' => 'Null Song', 'artist' => 'A', 'review_status' => null]);

        $needsReview = $this->actingAs($this->user)->getJson(route('songs.fetchSongs', ['review_status' => 'needs_review']));
        $needsReview->assertOk();
        $this->assertEquals(0, $needsReview->json('total'));

        $safe = $this->actingAs($this->user)->getJson(route('songs.fetchSongs', ['review_status' => 'safe']));
        $safe->assertOk();
        $this->assertEquals(0, $safe->json('total'));
    }

    /**
     * 楽曲マスタ一覧取得のテスト（review_statusフィルタ: safe）
     */
    public function test_fetch_songs_filter_by_safe(): void
    {
        Song::factory()->create(['title' => 'Safe Song', 'artist' => 'A', 'review_status' => 'safe']);
        Song::factory()->create(['title' => 'Review Song', 'artist' => 'B', 'review_status' => 'needs_review']);

        $response = $this->actingAs($this->user)->getJson(route('songs.fetchSongs', ['review_status' => 'safe']));

        $response->assertOk();
        $this->assertEquals(1, $response->json('total'));
        $this->assertEquals('Safe Song', $response->json('data.0.title'));
    }

    /**
     * 楽曲マスタ一覧取得のテスト（review_statusフィルタとsearch併用）
     */
    public function test_fetch_songs_filter_with_search(): void
    {
        Song::factory()->create(['title' => 'Good Song', 'artist' => 'A', 'review_status' => 'needs_review']);
        Song::factory()->create(['title' => 'Bad Song', 'artist' => 'A', 'review_status' => 'needs_review']);
        Song::factory()->create(['title' => 'Good Song', 'artist' => 'B', 'review_status' => 'safe']);

        $response = $this->actingAs($this->user)->getJson(route('songs.fetchSongs', [
            'review_status' => 'needs_review',
            'search' => 'Good',
        ]));

        $response->assertOk();
        $this->assertEquals(1, $response->json('total'));
        $this->assertEquals('Good Song', $response->json('data.0.title'));
    }

    public function test_fetch_songs_minus_search_excludes_keyword(): void
    {
        Song::factory()->create(['title' => 'Good Song', 'artist' => 'A']);
        Song::factory()->create(['title' => 'Bad Song', 'artist' => 'A']);
        Song::factory()->create(['title' => 'Great Song', 'artist' => 'B']);

        $response = $this->actingAs($this->user)->getJson(route('songs.fetchSongs', [
            'search' => 'Song -Bad',
        ]));

        $response->assertOk();
        $this->assertEquals(2, $response->json('total'));
        $titles = collect($response->json('data'))->pluck('title')->sort()->values()->all();
        $this->assertEquals(['Good Song', 'Great Song'], $titles);
    }

    public function test_fetch_songs_minus_search_by_artist(): void
    {
        Song::factory()->create(['title' => 'Song1', 'artist' => 'Alpha']);
        Song::factory()->create(['title' => 'Song2', 'artist' => 'Beta']);
        Song::factory()->create(['title' => 'Song3', 'artist' => 'Alpha']);

        $response = $this->actingAs($this->user)->getJson(route('songs.fetchSongs', [
            'search' => '-Beta',
        ]));

        $response->assertOk();
        $this->assertEquals(2, $response->json('total'));
    }

    /**
     * 楽曲マスタ一覧取得のテスト（あいまい検索: タイムスタンプをそのまま貼り付け）
     */
    public function test_fetch_songs_fuzzy_search_ignores_separators(): void
    {
        Song::factory()->create(['title' => 'ロキ', 'artist' => 'みきとP']);
        Song::factory()->create(['title' => '夜に駆ける', 'artist' => 'YOASOBI']);

        $response = $this->actingAs($this->user)->getJson(route('songs.fetchSongs', [
            'search' => 'ロキ / みきとP',
        ]));

        $response->assertOk();
        $this->assertEquals(1, $response->json('total'));
        $this->assertEquals('ロキ', $response->json('data.0.title'));
    }

    /**
     * 楽曲マスタ一覧取得のテスト（あいまい検索: 楽曲名とアーティスト名の順序は問わない）
     */
    public function test_fetch_songs_fuzzy_search_ignores_order(): void
    {
        Song::factory()->create(['title' => 'ロキ', 'artist' => 'みきとP']);

        $response = $this->actingAs($this->user)->getJson(route('songs.fetchSongs', [
            'search' => 'みきとP／ロキ',
        ]));

        $response->assertOk();
        $this->assertEquals(1, $response->json('total'));
    }

    /**
     * 楽曲マスタ一覧取得のテスト（あいまい検索: 全角・大文字小文字の差異を吸収）
     */
    public function test_fetch_songs_fuzzy_search_normalizes_input(): void
    {
        Song::factory()->create(['title' => 'Lemon', 'artist' => '米津玄師']);

        $response = $this->actingAs($this->user)->getJson(route('songs.fetchSongs', [
            'search' => 'ＬＥＭＯＮ － 米津玄師',
        ]));

        $response->assertOk();
        $this->assertEquals(1, $response->json('total'));
    }

    /**
     * 楽曲マスタ一覧取得のテスト（あいまい検索: 楽曲名に区切り文字を含む場合もヒットする）
     */
    public function test_fetch_songs_fuzzy_search_matches_title_with_separator(): void
    {
        Song::factory()->create(['title' => 'A/B', 'artist' => 'Artist']);

        $response = $this->actingAs($this->user)->getJson(route('songs.fetchSongs', [
            'search' => 'A/B / Artist',
        ]));

        $response->assertOk();
        $this->assertEquals(1, $response->json('total'));
    }

    /**
     * 楽曲マスタ一覧取得のテスト（完全一致検索: 入力文字列をそのまま検索する）
     */
    public function test_fetch_songs_exact_search_keeps_input_as_is(): void
    {
        Song::factory()->create(['title' => 'ロキ', 'artist' => 'みきとP']);

        // 完全一致検索では区切り文字を含むテキストはヒットしない
        $response = $this->actingAs($this->user)->getJson(route('songs.fetchSongs', [
            'search' => 'ロキ / みきとP',
            'search_mode' => 'exact',
        ]));

        $response->assertOk();
        $this->assertEquals(0, $response->json('total'));

        // 記号を含めて厳密に絞り込める
        $response = $this->actingAs($this->user)->getJson(route('songs.fetchSongs', [
            'search' => 'ロキ',
            'search_mode' => 'exact',
        ]));

        $response->assertOk();
        $this->assertEquals(1, $response->json('total'));
    }

    /**
     * 楽曲マスタ一覧取得のテスト（不正な検索モードはバリデーションエラー）
     */
    public function test_fetch_songs_rejects_invalid_search_mode(): void
    {
        $response = $this->actingAs($this->user)->getJson(route('songs.fetchSongs', [
            'search_mode' => 'invalid',
        ]));

        $response->assertStatus(422);
    }

    /**
     * 楽曲マスタ一覧取得のテスト（「(Music Video)」付きのテキストでも楽曲マスタが見つかること）
     *
     * 複合語のノイズワードが除去されないと検索語に 'music' が残り0件になる
     */
    public function test_fetch_songs_finds_song_with_compound_noise_in_search(): void
    {
        Song::factory()->create(['title' => '夜に駆ける', 'artist' => 'YOASOBI']);

        $response = $this->actingAs($this->user)->getJson(route('songs.fetchSongs', [
            'search' => '夜に駆ける / YOASOBI (Music Video)',
        ]));

        $response->assertOk();
        $this->assertEquals(1, $response->json('total'));
        $this->assertEquals('夜に駆ける', $response->json('data.0.title'));
    }

    /**
     * 楽曲マスタ一覧取得のテスト（記号・装飾のみの検索では全件を返さないこと）
     *
     * splitFuzzyKeywords() は記号のみの入力に対して空配列を返すため、
     * 対策をしないとWHERE句が1つも付かず全件が返ってしまう
     */
    public function test_fetch_songs_symbol_only_search_does_not_return_all(): void
    {
        Song::factory()->create(['title' => 'ロキ', 'artist' => 'みきとP']);
        Song::factory()->create(['title' => '夜に駆ける', 'artist' => 'YOASOBI']);

        $response = $this->actingAs($this->user)->getJson(route('songs.fetchSongs', [
            'search' => '/ - /',
        ]));

        $response->assertOk();
        $this->assertEquals(0, $response->json('total'));
    }

    /**
     * 楽曲マスタ一覧取得のテスト（search=0 でも全件を返さないこと）
     *
     * PHPの if ($search) は文字列の真偽値判定のため、"0" がfalse扱いになり
     * 検索条件が適用されず全件が返ってしまう
     */
    public function test_fetch_songs_search_zero_does_not_return_all(): void
    {
        Song::factory()->create(['title' => 'ロキ', 'artist' => 'みきとP']);
        Song::factory()->create(['title' => '夜に駆ける', 'artist' => 'YOASOBI']);

        $response = $this->actingAs($this->user)->getJson(route('songs.fetchSongs', [
            'search' => '0',
        ]));

        $response->assertOk();
        $this->assertEquals(0, $response->json('total'));
    }

    public function test_fetch_songs_includes_tags(): void
    {
        $song = Song::factory()->create();
        SongTag::factory()->create(['song_id' => $song->id, 'value' => 'タグX']);
        SongTag::factory()->create(['song_id' => $song->id, 'value' => 'タグY']);

        $response = $this->actingAs($this->user)->getJson(route('songs.fetchSongs'));

        $response->assertOk();
        $data = $response->json('data.0');
        $this->assertArrayHasKey('tags', $data);
        $this->assertCount(2, $data['tags']);
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

    public function test_store_song_strips_decorations(): void
    {
        $response = $this->actingAs($this->user)->postJson(route('songs.storeSong'), [
            'title' => '✦ アイドル ✨',
            'artist' => '♪ YOASOBI ♫',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('songs', [
            'title' => 'アイドル',
            'artist' => 'YOASOBI',
        ]);
    }

    public function test_store_song_detects_duplicate_with_decorations(): void
    {
        Song::factory()->create([
            'title' => 'アイドル',
            'artist' => 'YOASOBI',
        ]);

        $response = $this->actingAs($this->user)->postJson(route('songs.storeSong'), [
            'title' => '✦ アイドル',
            'artist' => 'YOASOBI',
        ]);

        $response->assertOk();
        $response->assertJson(['status' => 'exact_match']);
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
     * 「楽曲ではない」マークのテスト（textパラメータで正規化）
     */
    public function test_mark_as_not_song_with_text_parameter(): void
    {
        $rawText = 'テスト　Ｓｏｎｇ　～試験～';  // 正規化されていないテキスト
        $expectedNormalizedText = 'テスト song ~試験~';  // 正規化後

        $response = $this->actingAs($this->user)->postJson(route('songs.markAsNotSong'), [
            'text' => $rawText,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'message' => '楽曲ではないとマークしました。',
        ]);

        $this->assertDatabaseHas('timestamp_song_mappings', [
            'normalized_text' => $expectedNormalizedText,
            'is_not_song' => 1,
            'song_id' => null,
        ]);
    }

    /**
     * 「楽曲ではない」マークのテスト（正規化結果が空になるテキスト）
     */
    public function test_mark_as_not_song_with_dash_only_text(): void
    {
        $rawText = '-';  // 正規化すると空になるテキスト

        $response = $this->actingAs($this->user)->postJson(route('songs.markAsNotSong'), [
            'text' => $rawText,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'message' => '楽曲ではないとマークしました。',
        ]);

        // 正規化結果が空の場合は元のテキストが使用される
        $this->assertDatabaseHas('timestamp_song_mappings', [
            'normalized_text' => '-',
            'is_not_song' => 1,
            'song_id' => null,
        ]);
    }

    /**
     * 「楽曲ではない」マークのテスト（空文字列はエラー）
     */
    public function test_mark_as_not_song_with_empty_text_returns_error(): void
    {
        $response = $this->actingAs($this->user)->postJson(route('songs.markAsNotSong'), [
            'text' => '',
        ]);

        $response->assertStatus(422);
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
        Config::set('services.spotify.enabled', true);
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
     * Spotify検索のテスト（無効時）
     */
    public function test_search_spotify_disabled(): void
    {
        Config::set('services.spotify.enabled', false);

        $response = $this->actingAs($this->user)->getJson(route('songs.searchSpotify', [
            'query' => 'test query',
        ]));

        $response->assertStatus(503);
        $response->assertJson([
            'error' => 'Spotify API連携は現在無効になっています。',
        ]);
    }

    /**
     * Spotify検索のテスト（認証情報なし）
     */
    public function test_search_spotify_missing_credentials(): void
    {
        Config::set('services.spotify.enabled', true);
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

    /**
     * 自動紐付け確定のテスト（成功）
     */
    public function test_confirm_auto_link_success(): void
    {
        $song = Song::factory()->create();
        $mapping = TimestampSongMapping::factory()
            ->withSong($song)
            ->withText('Auto Linked Song')
            ->autoLinked()
            ->create();

        $this->assertFalse($mapping->is_manual);

        $response = $this->actingAs($this->user)->postJson(route('songs.confirmAutoLink'), [
            'normalized_text' => $mapping->normalized_text,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'message' => '自動紐付けを確定しました。',
        ]);

        $mapping->refresh();
        $this->assertTrue($mapping->is_manual);
        $this->assertEquals(1.0, $mapping->confidence);
    }

    /**
     * 自動紐付け確定のテスト（手動紐付けは確定不可）
     */
    public function test_confirm_auto_link_manual_mapping_not_found(): void
    {
        $song = Song::factory()->create();
        $mapping = TimestampSongMapping::factory()
            ->withSong($song)
            ->withText('Manual Linked Song')
            ->manual()
            ->create();

        $response = $this->actingAs($this->user)->postJson(route('songs.confirmAutoLink'), [
            'normalized_text' => $mapping->normalized_text,
        ]);

        $response->assertStatus(404);
    }

    /**
     * 自動紐付け確定のテスト（未紐付けは確定不可）
     */
    public function test_confirm_auto_link_unlinked_not_found(): void
    {
        $response = $this->actingAs($this->user)->postJson(route('songs.confirmAutoLink'), [
            'normalized_text' => 'nonexistent text',
        ]);

        $response->assertStatus(404);
    }

    /**
     * 自動紐付け確定のテスト（未認証アクセス）
     */
    public function test_confirm_auto_link_unauthenticated(): void
    {
        $this->postJson(route('songs.confirmAutoLink'), [
            'normalized_text' => 'test',
        ])->assertStatus(401);
    }

    /**
     * 動画秒数取得のテスト（YouTube URL成功）
     */
    public function test_fetch_video_duration_with_youtube_url_success(): void
    {
        // YouTubeApiServiceをモック
        $mockYoutubeService = \Mockery::mock(\App\Services\YouTubeApiService::class);
        $mockYoutubeService
            ->shouldReceive('extractVideoId')
            ->with('https://www.youtube.com/watch?v=dQw4w9WgXcQ')
            ->andReturn('dQw4w9WgXcQ');
        $mockYoutubeService
            ->shouldReceive('getVideoDuration')
            ->with('dQw4w9WgXcQ')
            ->andReturn(213000);

        $this->app->instance(\App\Services\YouTubeApiService::class, $mockYoutubeService);

        $response = $this->actingAs($this->user)->postJson(route('songs.fetchVideoDuration'), [
            'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'duration_ms' => 213000,
            'video_id' => 'dQw4w9WgXcQ',
            'platform' => 'youtube',
        ]);
        $this->assertNull($response->json('error'));
    }

    /**
     * 動画秒数取得のテスト（ニコニコ動画URL成功）
     */
    public function test_fetch_video_duration_with_niconico_url_success(): void
    {
        Http::fake([
            'api.search.nicovideo.jp/*' => Http::response([
                'data' => [
                    [
                        'contentId' => 'sm12345678',
                        'lengthSeconds' => 240,
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)->postJson(route('songs.fetchVideoDuration'), [
            'video_url' => 'https://www.nicovideo.jp/watch/sm12345678',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'duration_ms' => 240000,
            'video_id' => 'sm12345678',
            'platform' => 'niconico',
        ]);
        $this->assertNull($response->json('error'));
    }

    /**
     * 動画秒数取得のテスト（バリデーションエラー - URL未指定）
     */
    public function test_fetch_video_duration_validation_error(): void
    {
        $response = $this->actingAs($this->user)->postJson(route('songs.fetchVideoDuration'), [
            'video_url' => '',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['video_url']);
    }

    /**
     * 動画秒数取得のテスト（未対応プラットフォーム）
     */
    public function test_fetch_video_duration_with_unsupported_platform(): void
    {
        $response = $this->actingAs($this->user)->postJson(route('songs.fetchVideoDuration'), [
            'video_url' => 'https://vimeo.com/12345678',
        ]);

        $response->assertStatus(422);
        $this->assertNull($response->json('platform'));
        $this->assertStringContainsString('対応していないURL', $response->json('error'));
    }

    /**
     * 動画秒数取得のテスト（未認証アクセス）
     */
    public function test_fetch_video_duration_unauthenticated(): void
    {
        $this->postJson(route('songs.fetchVideoDuration'), [
            'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ])->assertStatus(401);
    }

    /**
     * 動画秒数取得のテスト（YouTube動画が見つからない場合）
     */
    public function test_fetch_video_duration_youtube_video_not_found(): void
    {
        $mockYoutubeService = \Mockery::mock(\App\Services\YouTubeApiService::class);
        $mockYoutubeService
            ->shouldReceive('extractVideoId')
            ->with('https://www.youtube.com/watch?v=nonexistent1')
            ->andReturn('nonexistent1');
        $mockYoutubeService
            ->shouldReceive('getVideoDuration')
            ->with('nonexistent1')
            ->andReturn(null);

        $this->app->instance(\App\Services\YouTubeApiService::class, $mockYoutubeService);

        $response = $this->actingAs($this->user)->postJson(route('songs.fetchVideoDuration'), [
            'video_url' => 'https://www.youtube.com/watch?v=nonexistent1',
        ]);

        $response->assertStatus(422);
        $this->assertEquals('youtube', $response->json('platform'));
        $this->assertNotNull($response->json('error'));
    }

    // ==========================================
    // updateSong のテスト
    // ==========================================

    /**
     * 楽曲マスタの更新テスト（タイトルとアーティスト）
     */
    public function test_update_song_title_and_artist(): void
    {
        $song = Song::factory()->create([
            'title' => 'Original Title',
            'artist' => 'Original Artist',
        ]);

        $response = $this->actingAs($this->user)->putJson(route('songs.updateSong', $song->id), [
            'title' => 'Updated Title',
            'artist' => 'Updated Artist',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'message' => '楽曲マスタを更新しました。',
        ]);
        $this->assertDatabaseHas('songs', [
            'id' => $song->id,
            'title' => 'Updated Title',
            'artist' => 'Updated Artist',
        ]);
    }

    /**
     * 楽曲マスタの更新テスト（video_url付き）
     */
    public function test_update_song_with_video_url(): void
    {
        $song = Song::factory()->create();

        $response = $this->actingAs($this->user)->putJson(route('songs.updateSong', $song->id), [
            'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('songs', [
            'id' => $song->id,
            'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ]);
    }

    /**
     * 楽曲マスタの更新テスト（duration_ms付き）
     */
    public function test_update_song_with_duration_ms(): void
    {
        $song = Song::factory()->create();

        $response = $this->actingAs($this->user)->putJson(route('songs.updateSong', $song->id), [
            'duration_ms' => 213000,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('songs', [
            'id' => $song->id,
            'duration_ms' => 213000,
        ]);
    }

    /**
     * 楽曲マスタの更新テスト（video_urlとduration_msを同時に更新）
     */
    public function test_update_song_with_video_url_and_duration(): void
    {
        $song = Song::factory()->create();

        $response = $this->actingAs($this->user)->putJson(route('songs.updateSong', $song->id), [
            'video_url' => 'https://www.nicovideo.jp/watch/sm12345678',
            'duration_ms' => 240000,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('songs', [
            'id' => $song->id,
            'video_url' => 'https://www.nicovideo.jp/watch/sm12345678',
            'duration_ms' => 240000,
        ]);
    }

    /**
     * 楽曲マスタの更新テスト（無効なURL）
     */
    public function test_update_song_with_invalid_url(): void
    {
        $song = Song::factory()->create();

        $response = $this->actingAs($this->user)->putJson(route('songs.updateSong', $song->id), [
            'video_url' => 'not-a-valid-url',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['video_url']);
    }

    /**
     * 楽曲マスタの更新テスト（duration_msが範囲外）
     */
    public function test_update_song_with_duration_out_of_range(): void
    {
        $song = Song::factory()->create();

        // 負の値
        $response = $this->actingAs($this->user)->putJson(route('songs.updateSong', $song->id), [
            'duration_ms' => -1,
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['duration_ms']);

        // 24時間を超える値
        $response = $this->actingAs($this->user)->putJson(route('songs.updateSong', $song->id), [
            'duration_ms' => 86400001, // 24時間 + 1ms
        ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['duration_ms']);
    }

    /**
     * 楽曲マスタの更新テスト（存在しないID）
     */
    public function test_update_song_not_found(): void
    {
        $response = $this->actingAs($this->user)->putJson(route('songs.updateSong', 'non-existent-id'), [
            'title' => 'Test',
        ]);

        $response->assertStatus(404);
    }

    /**
     * 楽曲マスタの更新テスト（未認証）
     */
    public function test_update_song_unauthenticated(): void
    {
        $song = Song::factory()->create();

        $this->putJson(route('songs.updateSong', $song->id), [
            'title' => 'Test',
        ])->assertStatus(401);
    }

    /**
     * 楽曲マスタの更新テスト（video_urlをnullにクリア）
     */
    public function test_update_song_clear_video_url(): void
    {
        $song = Song::factory()->withYoutubeUrl()->create();

        $response = $this->actingAs($this->user)->putJson(route('songs.updateSong', $song->id), [
            'video_url' => null,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('songs', [
            'id' => $song->id,
            'video_url' => null,
        ]);
    }

    // ==========================================
    // markAsPending のテスト
    // ==========================================

    /**
     * 保留状態にするテスト（既存の紐付けを保留に変更）
     */
    public function test_mark_as_pending_with_existing_mapping(): void
    {
        $song = Song::factory()->create();
        $mapping = TimestampSongMapping::factory()
            ->withSong($song)
            ->withText('Test Song')
            ->autoLinked()
            ->create();

        $response = $this->actingAs($this->user)->postJson(route('songs.markAsPending'), [
            'normalized_text' => $mapping->normalized_text,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'message' => '保留状態にしました。',
        ]);

        $mapping->refresh();
        $this->assertEquals(TimestampSongMapping::STATUS_PENDING, $mapping->status);
        $this->assertNull($mapping->song_id);
        $this->assertFalse($mapping->is_not_song);
        $this->assertTrue($mapping->is_manual);
    }

    /**
     * 保留状態にするテスト（新規作成）
     */
    public function test_mark_as_pending_creates_new_mapping(): void
    {
        $normalizedText = 'new pending song';

        $response = $this->actingAs($this->user)->postJson(route('songs.markAsPending'), [
            'normalized_text' => $normalizedText,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'message' => '保留状態にしました。',
        ]);

        $this->assertDatabaseHas('timestamp_song_mappings', [
            'normalized_text' => $normalizedText,
            'status' => TimestampSongMapping::STATUS_PENDING,
            'song_id' => null,
            'is_not_song' => false,
            'is_manual' => true,
        ]);
    }

    /**
     * 保留状態にするテスト（未認証アクセス）
     */
    public function test_mark_as_pending_unauthenticated(): void
    {
        $this->postJson(route('songs.markAsPending'), [
            'normalized_text' => 'test',
        ])->assertStatus(401);
    }

    /**
     * タイムスタンプ一覧取得のテスト（フィルター: pending）
     */
    public function test_fetch_timestamps_with_pending_filter(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);

        // 保留状態のタイムスタンプ
        $pendingTs = TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'Pending Song',
            'is_display' => 1,
        ]);

        TimestampSongMapping::factory()
            ->withText($pendingTs->text)
            ->pending()
            ->create();

        // 通常の紐付け済みタイムスタンプ
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

        // 未紐付けタイムスタンプ
        TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'Unlinked Song',
            'is_display' => 1,
        ]);

        $response = $this->actingAs($this->user)->getJson(route('songs.fetchTimestamps', [
            'filter' => 'pending',
        ]));

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('total'));
        $this->assertEquals('Pending Song', $response->json('data.0.text'));
    }

    /**
     * activeフィルターのテスト
     * 非楽曲(is_not_song)と保留(pending)を除外し、未紐付け・紐付け済み・自動紐付けを含むこと
     */
    public function test_fetch_timestamps_with_active_filter(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);

        // 未紐付けタイムスタンプ（含まれるべき）
        TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'Unlinked Song',
            'is_display' => 1,
        ]);

        // 紐付け済みタイムスタンプ（含まれるべき）
        $linkedTs = TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'Linked Song',
            'is_display' => 1,
        ]);

        $song1 = Song::factory()->create();
        TimestampSongMapping::factory()
            ->withSong($song1)
            ->withText($linkedTs->text)
            ->create();

        // 自動紐付けタイムスタンプ（含まれるべき）
        $autoLinkedTs = TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'Auto Linked Song',
            'is_display' => 1,
        ]);

        $song2 = Song::factory()->create();
        TimestampSongMapping::factory()
            ->withSong($song2)
            ->withText($autoLinkedTs->text)
            ->autoLinked()
            ->create();

        // 非楽曲タイムスタンプ（除外されるべき）
        $notSongTs = TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'Not A Song',
            'is_display' => 1,
        ]);

        TimestampSongMapping::factory()
            ->withText($notSongTs->text)
            ->notSong()
            ->create();

        // 保留状態タイムスタンプ（除外されるべき）
        $pendingTs = TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'Pending Song',
            'is_display' => 1,
        ]);

        TimestampSongMapping::factory()
            ->withText($pendingTs->text)
            ->pending()
            ->create();

        $response = $this->actingAs($this->user)->getJson(route('songs.fetchTimestamps', [
            'filter' => 'active',
        ]));

        $response->assertStatus(200);
        $this->assertEquals(3, $response->json('total'));

        // 返却されたテキストに非楽曲と保留が含まれないこと
        $texts = collect($response->json('data'))->pluck('text')->toArray();
        $this->assertContains('Unlinked Song', $texts);
        $this->assertContains('Linked Song', $texts);
        $this->assertContains('Auto Linked Song', $texts);
        $this->assertNotContains('Not A Song', $texts);
        $this->assertNotContains('Pending Song', $texts);
    }

    /**
     * 保留状態から紐付けするとlinked状態に戻るテスト
     */
    public function test_link_timestamp_from_pending_restores_linked_status(): void
    {
        $song = Song::factory()->create();
        $mapping = TimestampSongMapping::factory()
            ->withText('Pending Song')
            ->pending()
            ->create();

        $response = $this->actingAs($this->user)->postJson(route('songs.linkTimestamp'), [
            'normalized_text' => $mapping->normalized_text,
            'song_id' => $song->id,
        ]);

        $response->assertStatus(200);

        $mapping->refresh();
        $this->assertEquals(TimestampSongMapping::STATUS_LINKED, $mapping->status);
        $this->assertEquals($song->id, $mapping->song_id);
        $this->assertTrue($mapping->is_manual);
    }

    /**
     * linked フィルターは pending 状態を除外するテスト
     */
    public function test_fetch_timestamps_linked_filter_excludes_pending(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);

        // 保留状態のタイムスタンプ（linkedフィルターでは表示されない）
        $pendingTs = TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'Pending Song',
            'is_display' => 1,
        ]);

        TimestampSongMapping::factory()
            ->withText($pendingTs->text)
            ->pending()
            ->create();

        // 通常の紐付け済みタイムスタンプ（linkedフィルターで表示される）
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

        $response = $this->actingAs($this->user)->getJson(route('songs.fetchTimestamps', [
            'filter' => 'linked',
        ]));

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('total'));
        $this->assertEquals('Linked Song', $response->json('data.0.text'));
    }

    /**
     * タイムスタンプ一覧にstatus情報が含まれるテスト
     */
    public function test_fetch_timestamps_includes_status_info(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);

        $tsItem = TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => 'Pending Song',
            'is_display' => 1,
        ]);

        TimestampSongMapping::factory()
            ->withText($tsItem->text)
            ->pending()
            ->create();

        $response = $this->actingAs($this->user)->getJson(route('songs.fetchTimestamps'));

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertEquals(TimestampSongMapping::STATUS_PENDING, $data[0]['status']);
    }
}
