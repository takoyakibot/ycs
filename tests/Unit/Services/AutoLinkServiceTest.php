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

    private function fakeSpotifyApi(array $tracks = []): void
    {
        Http::fake([
            'https://accounts.spotify.com/api/token' => Http::response([
                'access_token' => 'test_token',
            ], 200),
            'https://api.spotify.com/v1/search*' => Http::response([
                'tracks' => [
                    'items' => $tracks,
                ],
            ], 200),
        ]);

        config(['services.spotify.client_id' => 'test_id']);
        config(['services.spotify.client_secret' => 'test_secret']);
    }

    private function createTsItem(string $text, array $overrides = []): TsItem
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);

        return TsItem::factory()->create(array_merge([
            'video_id' => $archive->video_id,
            'text' => $text,
            'is_display' => 1,
        ], $overrides));
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
     * ❶ 既存DB照合: 区切りなしのタイムスタンプがnormalized_titleと一致する場合
     */
    public function test_auto_link_matches_existing_song_by_normalized_title(): void
    {
        $existingSong = Song::factory()->create([
            'title' => 'シャルル',
            'artist' => 'バルーン',
            'spotify_track_id' => 'existing_spotify_id',
        ]);

        $this->createTsItem('シャルル');

        // Spotify APIは呼ばれないはずだが、念のためモック
        $this->fakeSpotifyApi();

        $result = $this->service->autoLinkUnlinkedTimestamps(10);

        $this->assertEquals(1, $result['processed']);
        $this->assertEquals(1, $result['linked']);

        // 既存楽曲にマッピングされたことを確認
        $this->assertDatabaseHas('timestamp_song_mappings', [
            'song_id' => $existingSong->id,
            'is_manual' => false,
        ]);

        // Spotify APIが呼ばれていないことを確認（DBで解決できるため）
        Http::assertSentCount(0);
    }

    /**
     * ❶ 既存DB照合: 「楽曲名 / アーティスト名」形式で一致する場合
     */
    public function test_auto_link_matches_existing_song_with_separator(): void
    {
        $existingSong = Song::factory()->create([
            'title' => '千本桜',
            'artist' => '初音ミク',
        ]);

        // extractSongInfoは「artist / title」の順なので、
        // 「千本桜 / 初音ミク」→ artist=千本桜, title=初音ミク
        // artist部分でもnormalized_titleを検索するため「千本桜」が一致
        $this->createTsItem('千本桜 / 初音ミク');

        $this->fakeSpotifyApi();

        $result = $this->service->autoLinkUnlinkedTimestamps(10);

        $this->assertEquals(1, $result['linked']);
        $this->assertDatabaseHas('timestamp_song_mappings', [
            'song_id' => $existingSong->id,
        ]);
        Http::assertSentCount(0);
    }

    /**
     * ❷ Spotify検索: 逆検証で楽曲名が一致し、新規Song作成するテスト
     */
    public function test_auto_link_creates_song_from_timestamp_info_when_spotify_matches(): void
    {
        // タイムスタンプは「楽曲名 / アーティスト名」形式
        $this->createTsItem('テストソング / テストアーティスト');

        $this->fakeSpotifyApi([
            [
                'id' => 'spotify_track_123',
                'name' => 'テストソング',
                'artists' => [['name' => 'Test Artist']],
                'album' => ['name' => 'Test Album'],
            ],
        ]);

        $result = $this->service->autoLinkUnlinkedTimestamps(10);

        $this->assertEquals(1, $result['linked']);

        // タイムスタンプの情報で楽曲マスタが作成されたことを確認
        // extractSongInfo('テストソング / テストアーティスト') → artist=テストソング, title=テストアーティスト
        // だがfindMatchingTrackで「テストソング」がartist部分と一致
        // createSongFromTimestampでもextractSongInfoを使うので同じ分割
        $this->assertDatabaseHas('songs', [
            'spotify_track_id' => 'spotify_track_123',
        ]);

        $this->assertDatabaseHas('timestamp_song_mappings', [
            'is_manual' => false,
            'confidence' => 0.8,
        ]);
    }

    /**
     * ❷ Spotify検索: 逆検証で一致せず、未紐づけのままになるテスト
     */
    public function test_auto_link_does_not_link_when_spotify_name_does_not_match(): void
    {
        // 「18:30 AAA」のようなリレーの時間割テキスト
        $this->createTsItem('AAA');

        $this->fakeSpotifyApi([
            [
                'id' => 'spotify_track_999',
                'name' => 'Completely Different Song',
                'artists' => [['name' => 'Some Artist']],
            ],
        ]);

        $result = $this->service->autoLinkUnlinkedTimestamps(10);

        $this->assertEquals(1, $result['processed']);
        $this->assertEquals(0, $result['linked']);
        $this->assertEquals(1, $result['failed']); // not_found扱い

        // マッピングが作成されていないことを確認
        $this->assertDatabaseCount('timestamp_song_mappings', 0);
        // 楽曲マスタも作成されていないことを確認
        $this->assertDatabaseCount('songs', 0);
    }

    /**
     * ❷ Spotify検索: 既存Spotify Track IDがある場合、既存楽曲を使用するテスト
     *
     * ❶のDB照合でヒットしないよう、songsのtitleとタイムスタンプのtextを異なる値にする。
     * Spotify検索結果のtrack nameが逆検証で一致し、そのSpotify IDで既存songが見つかるケース。
     */
    public function test_auto_link_uses_existing_song_by_spotify_id(): void
    {
        // 楽曲マスタのtitleはタイムスタンプのtextと異なる（❶でヒットしない）
        $existingSong = Song::factory()->create([
            'title' => 'Some Other Title',
            'artist' => 'Some Artist',
            'spotify_track_id' => 'existing_spotify_id',
        ]);

        $this->createTsItem('テスト楽曲');

        $this->fakeSpotifyApi([
            [
                'id' => 'existing_spotify_id',
                'name' => 'テスト楽曲',
                'artists' => [['name' => 'Some Artist']],
            ],
        ]);

        $result = $this->service->autoLinkUnlinkedTimestamps(10);

        $this->assertEquals(1, $result['linked']);
        $this->assertDatabaseCount('songs', 1);
        $this->assertDatabaseHas('timestamp_song_mappings', [
            'song_id' => $existingSong->id,
        ]);
    }

    /**
     * Spotify検索で結果がない場合のテスト
     */
    public function test_auto_link_no_spotify_results(): void
    {
        $this->createTsItem('Unknown Song');
        $this->fakeSpotifyApi([]);

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
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);

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

        $this->fakeSpotifyApi();

        $result = $this->service->autoLinkUnlinkedTimestamps(10);

        $this->assertEquals(0, $result['processed']);
    }

    /**
     * is_display=0のタイムスタンプは処理対象外のテスト
     */
    public function test_auto_link_skips_hidden_timestamps(): void
    {
        $this->createTsItem('Hidden Song', ['is_display' => 0]);

        $result = $this->service->autoLinkUnlinkedTimestamps(10);

        $this->assertEquals(0, $result['processed']);
    }

    /**
     * type='3'（歌ってみた/カバー曲）は処理対象外のテスト
     */
    public function test_auto_link_skips_cover_song_timestamps(): void
    {
        $this->createTsItem('Cover Song', ['type' => '3']);

        $result = $this->service->autoLinkUnlinkedTimestamps(10);

        $this->assertEquals(0, $result['processed']);
    }

    /**
     * 処理件数上限のテスト
     */
    public function test_auto_link_respects_limit(): void
    {
        $channel = Channel::factory()->create();
        $archive = Archive::factory()->create(['channel_id' => $channel->channel_id]);

        for ($i = 1; $i <= 5; $i++) {
            TsItem::factory()->create([
                'video_id' => $archive->video_id,
                'text' => "Song {$i}",
                'is_display' => 1,
            ]);
        }

        $this->fakeSpotifyApi([
            [
                'id' => 'spotify_track_'.uniqid(),
                'name' => 'Song',
                'artists' => [['name' => 'Artist']],
            ],
        ]);

        $result = $this->service->autoLinkUnlinkedTimestamps(2);

        $this->assertEquals(2, $result['processed']);
    }

    /**
     * 文字バリエーション（例: ' vs '）がある場合でも既存楽曲を検出するテスト
     */
    public function test_auto_link_detects_existing_song_with_character_variants(): void
    {
        // 既存楽曲を作成（シングルクォート ' U+0027 を使用）
        $existingSong = Song::factory()->create([
            'title' => "Don't say \"lazy\"",
            'artist' => '桜高軽音部',
            'spotify_track_id' => 'existing_spotify_id',
        ]);

        // タイムスタンプはUnicodeのクォートを使用
        $this->createTsItem("Don\xE2\x80\x99t say \xE2\x80\x9Clazy\xE2\x80\x9D / 桜高軽音部");

        $this->fakeSpotifyApi([
            [
                'id' => 'new_spotify_id',
                'name' => "Don\xE2\x80\x99t say \xE2\x80\x9Clazy\xE2\x80\x9D",
                'artists' => [['name' => '桜高軽音部']],
            ],
        ]);

        $result = $this->service->autoLinkUnlinkedTimestamps(10);

        $this->assertEquals(1, $result['processed']);
        $this->assertEquals(1, $result['linked']);

        // 新しい楽曲マスタが作成されていないことを確認（既存楽曲を使用）
        $this->assertDatabaseCount('songs', 1);

        $this->assertDatabaseHas('timestamp_song_mappings', [
            'song_id' => $existingSong->id,
        ]);
    }

    /**
     * 進捗コールバックが呼び出されるテスト
     */
    public function test_auto_link_calls_progress_callback(): void
    {
        $existingSong = Song::factory()->create([
            'title' => 'Test Song',
            'artist' => 'Test Artist',
        ]);

        $this->createTsItem('Test Song');

        $this->fakeSpotifyApi();

        $messages = [];
        $result = $this->service->autoLinkUnlinkedTimestamps(10, function ($message) use (&$messages) {
            $messages[] = $message;
        });

        $this->assertNotEmpty($messages);
        $this->assertStringContainsString('処理します', $messages[0]);
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

    /**
     * Spotify検索結果の上位N件から一致するトラックを見つけるテスト
     */
    public function test_auto_link_finds_match_in_multiple_spotify_results(): void
    {
        $this->createTsItem('ターゲット楽曲');

        $this->fakeSpotifyApi([
            [
                'id' => 'spotify_track_1',
                'name' => 'Unrelated Song',
                'artists' => [['name' => 'Artist 1']],
            ],
            [
                'id' => 'spotify_track_2',
                'name' => 'ターゲット楽曲',
                'artists' => [['name' => 'Artist 2']],
            ],
            [
                'id' => 'spotify_track_3',
                'name' => 'Another Song',
                'artists' => [['name' => 'Artist 3']],
            ],
        ]);

        $result = $this->service->autoLinkUnlinkedTimestamps(10);

        $this->assertEquals(1, $result['linked']);

        // 2番目のトラックのSpotify IDで作成されたことを確認
        $this->assertDatabaseHas('songs', [
            'spotify_track_id' => 'spotify_track_2',
        ]);
    }

    /**
     * 新規Song作成時にタイムスタンプの情報（extractSongInfo）が使われるテスト
     */
    public function test_creates_song_with_timestamp_song_info(): void
    {
        $this->createTsItem('アーティスト名 / 楽曲タイトル');

        $this->fakeSpotifyApi([
            [
                'id' => 'spotify_new_123',
                'name' => '楽曲タイトル',
                'artists' => [['name' => 'Spotify Artist Name']],
            ],
        ]);

        $result = $this->service->autoLinkUnlinkedTimestamps(10);

        $this->assertEquals(1, $result['linked']);

        // extractSongInfoの結果で登録される
        // 「アーティスト名 / 楽曲タイトル」→ artist=アーティスト名, title=楽曲タイトル
        $song = Song::where('spotify_track_id', 'spotify_new_123')->first();
        $this->assertNotNull($song);
        // Spotifyのアーティスト名ではなくタイムスタンプの情報が使われる
        $this->assertNotEquals('Spotify Artist Name', $song->artist);
    }
}
