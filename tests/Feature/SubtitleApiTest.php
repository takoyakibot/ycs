<?php

namespace Tests\Feature;

use App\Models\Archive;
use App\Models\Channel;
use App\Models\Song;
use App\Models\SubtitleFingerprint;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use App\Models\User;
use App\Models\VideoSubtitle;
use App\Services\SubtitleFingerprintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubtitleApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * フィンガープリントの最小トライグラム数を満たす歌詞相当のテキスト
     */
    private const LYRICS_TEXT = 'あいうえおかきくけこさしすせそたちつてとなにぬねの';

    protected User $superAdmin;

    protected User $channelAdmin;

    protected Channel $channel;

    protected Archive $archive;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create([
            'email_verified_at' => now(),
            'role' => User::ROLE_SUPER_ADMIN,
        ]);

        $this->channelAdmin = User::factory()->create([
            'email_verified_at' => now(),
            'role' => User::ROLE_ADMIN,
        ]);

        $this->channel = Channel::factory()->create([
            'user_id' => $this->channelAdmin->id,
        ]);

        $this->archive = Archive::factory()->create([
            'channel_id' => $this->channel->channel_id,
            'video_id' => 'dQw4w9WgXcQ',
        ]);
    }

    // ==========================================
    // store（字幕保存）のテスト
    // ==========================================

    public function test_store_subtitles_successfully(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->postJson('/api/manage/archives/subtitles/store', [
                'video_id' => 'dQw4w9WgXcQ',
                'language_code' => 'ja',
                'kind' => 'asr',
                'subtitles' => [
                    ['start' => 0, 'duration' => 2.5, 'text' => 'こんにちは'],
                    ['start' => 2.5, 'duration' => 3.0, 'text' => '今日は歌枠です'],
                ],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('segment_count', 2)
            ->assertJsonPath('is_new', true);

        $this->assertDatabaseHas('video_subtitles', [
            'video_id' => 'dQw4w9WgXcQ',
            'language_code' => 'ja',
            'kind' => 'asr',
            'segment_count' => 2,
        ]);
    }

    public function test_store_subtitles_upsert(): void
    {
        // 初回保存
        $this->actingAs($this->superAdmin)
            ->postJson('/api/manage/archives/subtitles/store', [
                'video_id' => 'dQw4w9WgXcQ',
                'language_code' => 'ja',
                'kind' => 'asr',
                'subtitles' => [
                    ['start' => 0, 'duration' => 2.5, 'text' => 'テスト1'],
                ],
            ]);

        // 同じキーで更新
        $response = $this->actingAs($this->superAdmin)
            ->postJson('/api/manage/archives/subtitles/store', [
                'video_id' => 'dQw4w9WgXcQ',
                'language_code' => 'ja',
                'kind' => 'asr',
                'subtitles' => [
                    ['start' => 0, 'duration' => 2.5, 'text' => 'テスト2'],
                    ['start' => 2.5, 'duration' => 3.0, 'text' => 'テスト3'],
                ],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('segment_count', 2)
            ->assertJsonPath('is_new', false);

        // レコードは1件のみ
        $this->assertEquals(1, VideoSubtitle::where('video_id', 'dQw4w9WgXcQ')->count());
    }

    public function test_store_subtitles_requires_auth(): void
    {
        $response = $this->postJson('/api/manage/archives/subtitles/store', [
            'video_id' => 'dQw4w9WgXcQ',
            'language_code' => 'ja',
            'kind' => '',
            'subtitles' => [['start' => 0, 'duration' => 1, 'text' => 'test']],
        ]);

        $response->assertStatus(401);
    }

    public function test_store_subtitles_accepts_sanctum_bearer_token(): void
    {
        // Chrome拡張はセッションを持たず、APIトークン（Bearer）のみで送信する。
        // web.phpに同一URIのルートがあるとSanctum認証ルートが上書きされ、
        // CSRF検証（419）や認証エラーになるため、実際の拡張と同じ経路で検証する
        $token = $this->superAdmin->createToken('extension')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/manage/archives/subtitles/store', [
                'video_id' => 'dQw4w9WgXcQ',
                'language_code' => 'ja',
                'kind' => 'asr',
                'subtitles' => [
                    ['start' => 0, 'duration' => 2.5, 'text' => 'こんにちは'],
                ],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('segment_count', 1);
    }

    public function test_store_subtitles_validates_video_id(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->postJson('/api/manage/archives/subtitles/store', [
                'video_id' => 'invalid',
                'language_code' => 'ja',
                'kind' => '',
                'subtitles' => [['start' => 0, 'duration' => 1, 'text' => 'test']],
            ]);

        $response->assertStatus(422);
    }

    public function test_store_subtitles_returns_404_for_unknown_video(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->postJson('/api/manage/archives/subtitles/store', [
                'video_id' => 'xxxxxxxxxxx',
                'language_code' => 'ja',
                'kind' => '',
                'subtitles' => [['start' => 0, 'duration' => 1, 'text' => 'test']],
            ]);

        $response->assertStatus(404);
    }

    public function test_store_subtitles_denied_for_other_user(): void
    {
        $otherUser = User::factory()->create([
            'email_verified_at' => now(),
            'role' => User::ROLE_ADMIN,
        ]);

        $response = $this->actingAs($otherUser)
            ->postJson('/api/manage/archives/subtitles/store', [
                'video_id' => 'dQw4w9WgXcQ',
                'language_code' => 'ja',
                'kind' => '',
                'subtitles' => [['start' => 0, 'duration' => 1, 'text' => 'test']],
            ]);

        $response->assertStatus(403);
    }

    public function test_store_subtitles_generates_fingerprints(): void
    {
        // ts_itemを作成
        TsItem::factory()->create([
            'video_id' => 'dQw4w9WgXcQ',
            'ts_num' => 60,
            'text' => 'テスト曲',
            'is_display' => '1',
        ]);

        $response = $this->actingAs($this->superAdmin)
            ->postJson('/api/manage/archives/subtitles/store', [
                'video_id' => 'dQw4w9WgXcQ',
                'language_code' => 'ja',
                'kind' => 'asr',
                'subtitles' => [
                    ['start' => 55, 'duration' => 3, 'text' => 'さあ歌いましょう'],
                    ['start' => 58, 'duration' => 3, 'text' => 'この曲は素敵です'],
                    ['start' => 61, 'duration' => 3, 'text' => '歌声が響きます'],
                    ['start' => 64, 'duration' => 3, 'text' => 'とても美しい'],
                    ['start' => 67, 'duration' => 3, 'text' => 'メロディーですね'],
                ],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('fingerprints_generated', 1);

        $this->assertEquals(1, SubtitleFingerprint::count());
    }

    // ==========================================
    // show（字幕取得）のテスト
    // ==========================================

    public function test_show_stored_subtitles(): void
    {
        VideoSubtitle::create([
            'id' => Str::ulid(),
            'video_id' => 'dQw4w9WgXcQ',
            'language_code' => 'ja',
            'kind' => 'asr',
            'subtitle_data' => [['start' => 0, 'duration' => 1, 'text' => 'test']],
            'segment_count' => 1,
        ]);

        $response = $this->actingAs($this->superAdmin)
            ->getJson('/api/manage/archives/subtitles/stored?video_id=dQw4w9WgXcQ');

        $response->assertStatus(200)
            ->assertJsonPath('video_id', 'dQw4w9WgXcQ')
            ->assertJsonCount(1, 'subtitles')
            ->assertJsonPath('subtitles.0.language_code', 'ja');
    }

    public function test_show_stored_subtitles_requires_auth(): void
    {
        $response = $this->getJson('/api/manage/archives/subtitles/stored?video_id=dQw4w9WgXcQ');

        $response->assertStatus(401);
    }

    public function test_show_stored_subtitles_denied_for_other_user(): void
    {
        $otherUser = User::factory()->create([
            'email_verified_at' => now(),
            'role' => User::ROLE_ADMIN,
        ]);

        $response = $this->actingAs($otherUser)
            ->getJson('/api/manage/archives/subtitles/stored?video_id=dQw4w9WgXcQ');

        $response->assertStatus(403);
    }

    public function test_show_stored_subtitles_returns_404_for_unknown_video(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->getJson('/api/manage/archives/subtitles/stored?video_id=xxxxxxxxxxx');

        $response->assertStatus(404);
    }

    // ==========================================
    // フィンガープリント生成のテスト
    // ==========================================

    public function test_store_generates_fingerprint_with_configured_window(): void
    {
        $tsItem = TsItem::factory()->create([
            'video_id' => 'dQw4w9WgXcQ',
            'ts_num' => 60,
            'text' => 'テスト曲',
            'is_display' => '1',
        ]);

        // 前奏が長く、歌詞は歌い出しの45秒後から出はじめる
        $response = $this->actingAs($this->superAdmin)
            ->postJson('/api/manage/archives/subtitles/store', [
                'video_id' => 'dQw4w9WgXcQ',
                'language_code' => 'ja',
                'kind' => 'asr',
                'subtitles' => [
                    ['start' => 60, 'duration' => 40, 'text' => '[音楽]'],
                    ['start' => 105, 'duration' => 5, 'text' => self::LYRICS_TEXT],
                ],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('fingerprints_generated', 1);

        $this->assertDatabaseHas('subtitle_fingerprints', [
            'ts_item_id' => $tsItem->id,
            'duration_sec' => SubtitleFingerprintService::WINDOW_DURATION_SEC,
        ]);
    }

    public function test_store_skips_fingerprint_for_music_only_window(): void
    {
        $tsItem = TsItem::factory()->create([
            'video_id' => 'dQw4w9WgXcQ',
            'ts_num' => 60,
            'text' => 'テスト曲',
            'is_display' => '1',
        ]);

        // 旧仕様で作られた既存のフィンガープリント。1件も生成されない場合でも削除される
        SubtitleFingerprint::create([
            'id' => Str::ulid(),
            'video_id' => 'dQw4w9WgXcQ',
            'ts_item_id' => $tsItem->id,
            'start_sec' => 60,
            'duration_sec' => 30,
            'fingerprint_text' => self::LYRICS_TEXT,
            'trigrams' => SubtitleFingerprintService::generateTrigrams(self::LYRICS_TEXT),
        ]);

        // 窓が効果音アノテーションだけの場合、トライグラムが数種類まで縮退して
        // 別の楽曲と一致してしまうため、フィンガープリントを作らない
        $response = $this->actingAs($this->superAdmin)
            ->postJson('/api/manage/archives/subtitles/store', [
                'video_id' => 'dQw4w9WgXcQ',
                'language_code' => 'ja',
                'kind' => 'asr',
                'subtitles' => [
                    ['start' => 60, 'duration' => 30, 'text' => '[音楽]'],
                    ['start' => 90, 'duration' => 30, 'text' => '[音楽]'],
                ],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('fingerprints_generated', 0);

        $this->assertDatabaseCount('subtitle_fingerprints', 0);
    }

    public function test_store_prunes_stale_fingerprints(): void
    {
        $keptTsItem = TsItem::factory()->create([
            'video_id' => 'dQw4w9WgXcQ',
            'ts_num' => 60,
            'text' => '残る曲',
            'is_display' => '1',
        ]);

        $staleTsItem = TsItem::factory()->create([
            'video_id' => 'dQw4w9WgXcQ',
            'ts_num' => 600,
            'text' => '消える曲',
            'is_display' => '1',
        ]);

        // 旧仕様（30秒窓）で作られたフィンガープリントが残っている状態を作る
        SubtitleFingerprint::create([
            'id' => Str::ulid(),
            'video_id' => 'dQw4w9WgXcQ',
            'ts_item_id' => $staleTsItem->id,
            'start_sec' => 600,
            'duration_sec' => 30,
            'fingerprint_text' => self::LYRICS_TEXT,
            'trigrams' => SubtitleFingerprintService::generateTrigrams(self::LYRICS_TEXT),
        ]);

        // 再生成後、字幕のない ts_item のフィンガープリントは残らない
        $this->actingAs($this->superAdmin)
            ->postJson('/api/manage/archives/subtitles/store', [
                'video_id' => 'dQw4w9WgXcQ',
                'language_code' => 'ja',
                'kind' => 'asr',
                'subtitles' => [
                    ['start' => 60, 'duration' => 10, 'text' => self::LYRICS_TEXT],
                ],
            ])
            ->assertStatus(200)
            ->assertJsonPath('fingerprints_generated', 1);

        $this->assertDatabaseHas('subtitle_fingerprints', ['ts_item_id' => $keptTsItem->id]);
        $this->assertDatabaseMissing('subtitle_fingerprints', ['ts_item_id' => $staleTsItem->id]);
    }

    public function test_generate_command_rebuilds_fingerprints(): void
    {
        $tsItem = TsItem::factory()->create([
            'video_id' => 'dQw4w9WgXcQ',
            'ts_num' => 60,
            'text' => 'テスト曲',
            'is_display' => '1',
        ]);

        VideoSubtitle::create([
            'id' => Str::ulid(),
            'video_id' => 'dQw4w9WgXcQ',
            'language_code' => 'ja',
            'kind' => 'asr',
            'subtitle_data' => [
                ['start' => 60, 'duration' => 10, 'text' => self::LYRICS_TEXT],
            ],
            'segment_count' => 1,
        ]);

        $this->assertDatabaseCount('subtitle_fingerprints', 0);

        $this->artisan('subtitle-fingerprints:generate')
            ->assertExitCode(0);

        $this->assertDatabaseHas('subtitle_fingerprints', [
            'ts_item_id' => $tsItem->id,
            'duration_sec' => SubtitleFingerprintService::WINDOW_DURATION_SEC,
        ]);
    }

    // ==========================================
    // matchCandidates（楽曲マッチング）のテスト
    // ==========================================

    public function test_match_candidates_without_fingerprint(): void
    {
        $tsItem = TsItem::factory()->create([
            'video_id' => 'dQw4w9WgXcQ',
            'ts_num' => 60,
            'text' => 'テスト曲',
        ]);

        $response = $this->actingAs($this->superAdmin)
            ->getJson("/api/manage/subtitle-matches/{$tsItem->id}");

        $response->assertStatus(200)
            ->assertJsonPath('has_fingerprint', false)
            ->assertJsonCount(0, 'candidates');
    }

    public function test_match_candidates_with_fingerprint(): void
    {
        // 対象のts_item
        $tsItem1 = TsItem::factory()->create([
            'video_id' => 'dQw4w9WgXcQ',
            'ts_num' => 60,
            'text' => 'テスト曲A',
            'is_display' => '1',
        ]);

        // 別の動画で同じ曲を歌ったts_item（同チャンネル）
        $archive2 = Archive::factory()->create([
            'channel_id' => $this->channel->channel_id,
            'video_id' => 'abcdefghijk',
        ]);
        $tsItem2 = TsItem::factory()->create([
            'video_id' => 'abcdefghijk',
            'ts_num' => 120,
            'text' => 'テスト曲A',
            'is_display' => '1',
        ]);

        // 楽曲マスタとマッピングを作成
        $song = Song::factory()->create([
            'title' => 'テスト曲A',
            'artist' => 'テストアーティスト',
        ]);
        TimestampSongMapping::create([
            'id' => Str::ulid(),
            'normalized_text' => $tsItem2->normalized_text,
            'song_id' => $song->id,
            'is_not_song' => false,
        ]);

        // 類似フィンガープリントを作成
        $trigrams = SubtitleFingerprintService::generateTrigrams('あいうえおかきくけこさしすせそ');

        SubtitleFingerprint::create([
            'id' => Str::ulid(),
            'video_id' => 'dQw4w9WgXcQ',
            'ts_item_id' => $tsItem1->id,
            'start_sec' => 60,
            'duration_sec' => 30,
            'fingerprint_text' => 'あいうえおかきくけこさしすせそ',
            'trigrams' => $trigrams,
        ]);

        SubtitleFingerprint::create([
            'id' => Str::ulid(),
            'video_id' => 'abcdefghijk',
            'ts_item_id' => $tsItem2->id,
            'start_sec' => 120,
            'duration_sec' => 30,
            'fingerprint_text' => 'あいうえおかきくけこさしすせそ',
            'trigrams' => $trigrams,
        ]);

        $response = $this->actingAs($this->superAdmin)
            ->getJson("/api/manage/subtitle-matches/{$tsItem1->id}");

        $response->assertStatus(200)
            ->assertJsonPath('has_fingerprint', true)
            ->assertJsonPath('candidates.0.song_id', $song->id)
            ->assertJsonPath('candidates.0.song_title', 'テスト曲A')
            ->assertJsonPath('candidates.0.similarity', fn ($v) => abs($v - 1.0) < 0.001);
    }

    public function test_match_candidates_ignores_different_window_duration(): void
    {
        $tsItem1 = TsItem::factory()->create([
            'video_id' => 'dQw4w9WgXcQ',
            'ts_num' => 60,
            'text' => 'テスト曲A',
            'is_display' => '1',
        ]);

        Archive::factory()->create([
            'channel_id' => $this->channel->channel_id,
            'video_id' => 'abcdefghijk',
        ]);
        $tsItem2 = TsItem::factory()->create([
            'video_id' => 'abcdefghijk',
            'ts_num' => 120,
            'text' => 'テスト曲A',
            'is_display' => '1',
        ]);

        $trigrams = SubtitleFingerprintService::generateTrigrams(self::LYRICS_TEXT);

        // トライグラムは完全一致だが、窓の長さが異なる（旧仕様で作られた行）
        SubtitleFingerprint::create([
            'id' => Str::ulid(),
            'video_id' => 'dQw4w9WgXcQ',
            'ts_item_id' => $tsItem1->id,
            'start_sec' => 60,
            'duration_sec' => 60,
            'fingerprint_text' => self::LYRICS_TEXT,
            'trigrams' => $trigrams,
        ]);

        SubtitleFingerprint::create([
            'id' => Str::ulid(),
            'video_id' => 'abcdefghijk',
            'ts_item_id' => $tsItem2->id,
            'start_sec' => 120,
            'duration_sec' => 30,
            'fingerprint_text' => self::LYRICS_TEXT,
            'trigrams' => $trigrams,
        ]);

        // 窓の長さが違うものは同一楽曲でも類似度が構造的に下がるため比較しない
        $this->actingAs($this->superAdmin)
            ->getJson("/api/manage/subtitle-matches/{$tsItem1->id}")
            ->assertStatus(200)
            ->assertJsonPath('has_fingerprint', true)
            ->assertJsonCount(0, 'candidates');

        // 窓の長さを揃えれば候補として返る
        SubtitleFingerprint::where('ts_item_id', $tsItem2->id)->update(['duration_sec' => 60]);

        $this->actingAs($this->superAdmin)
            ->getJson("/api/manage/subtitle-matches/{$tsItem1->id}")
            ->assertStatus(200)
            ->assertJsonCount(1, 'candidates');
    }

    public function test_match_candidates_returns_404_for_unknown_ts_item(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->getJson('/api/manage/subtitle-matches/00000000000000000000000000');

        $response->assertStatus(404);
    }

    public function test_match_candidates_denied_for_other_user(): void
    {
        $tsItem = TsItem::factory()->create([
            'video_id' => 'dQw4w9WgXcQ',
            'ts_num' => 60,
            'text' => 'テスト',
        ]);

        $otherUser = User::factory()->create([
            'email_verified_at' => now(),
            'role' => User::ROLE_ADMIN,
        ]);

        $response = $this->actingAs($otherUser)
            ->getJson("/api/manage/subtitle-matches/{$tsItem->id}");

        $response->assertStatus(403);
    }
}
