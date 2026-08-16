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

/**
 * 拡張向け: 再生位置ベースの字幕マッチング候補API（#595）
 */
class ExtensionSubtitleMatchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * フィンガープリントの最小トライグラム数を満たす歌詞相当のテキスト
     */
    private const LYRICS_TEXT = 'あいうえおかきくけこさしすせそたちつてとなにぬねの';

    protected User $user;

    protected Channel $channel;

    protected Archive $archive;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email_verified_at' => now(),
            'role' => User::ROLE_ADMIN,
        ]);

        $this->channel = Channel::factory()->create(['user_id' => $this->user->id]);

        $this->archive = Archive::factory()->create([
            'channel_id' => $this->channel->channel_id,
            'video_id' => 'dQw4w9WgXcQ',
        ]);
    }

    private function requestMatch(string $videoId, int $sec): \Illuminate\Testing\TestResponse
    {
        $token = $this->user->createToken('extension')->plainTextToken;

        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/extension/subtitle-matches?video_id={$videoId}&sec={$sec}");
    }

    /**
     * 対象動画の字幕（sec=60の窓に歌詞が入る）を保存する
     */
    private function createTargetSubtitle(): void
    {
        VideoSubtitle::create([
            'id' => Str::ulid(),
            'video_id' => 'dQw4w9WgXcQ',
            'language_code' => 'ja',
            'kind' => 'asr',
            'subtitle_data' => [
                ['start' => 60, 'duration' => 5, 'text' => self::LYRICS_TEXT],
            ],
            'segment_count' => 1,
        ]);
    }

    /**
     * 同チャンネルの別動画に、楽曲マスタに紐付いた類似フィンガープリントを用意する
     */
    private function createMappedFingerprint(): Song
    {
        Archive::factory()->create([
            'channel_id' => $this->channel->channel_id,
            'video_id' => 'abcdefghijk',
        ]);
        $tsItem = TsItem::factory()->create([
            'video_id' => 'abcdefghijk',
            'ts_num' => 120,
            'text' => 'テスト曲A',
            'is_display' => '1',
        ]);

        $song = Song::factory()->create([
            'title' => 'テスト曲A',
            'artist' => 'テストアーティスト',
        ]);
        TimestampSongMapping::create([
            'id' => Str::ulid(),
            'normalized_text' => $tsItem->normalized_text,
            'song_id' => $song->id,
            'is_not_song' => false,
        ]);

        SubtitleFingerprint::create([
            'id' => Str::ulid(),
            'video_id' => 'abcdefghijk',
            'ts_item_id' => $tsItem->id,
            'start_sec' => 120,
            'duration_sec' => SubtitleFingerprintService::WINDOW_DURATION_SEC,
            'fingerprint_text' => self::LYRICS_TEXT,
            'trigrams' => SubtitleFingerprintService::generateTrigrams(self::LYRICS_TEXT),
        ]);

        return $song;
    }

    public function test_returns_candidates_for_moderately_similar_fingerprint(): void
    {
        // 実運用のASR字幕では同一曲でもJaccard類似度が0.2前後になることが多い
        // （2026-08-15の本番実測: 同一曲395組の88%が0.15以上、異曲ノイズは最大0.089）。
        // しきい値0.15でこの水準のペアが候補に出ることを検証する
        $this->createTargetSubtitle();
        $song = $this->createMappedFingerprint();

        // 保存済みFPのトライグラムを「窓と約0.21の類似度」になるよう部分一致に差し替える
        $windowTrigrams = SubtitleFingerprintService::generateTrigrams(self::LYRICS_TEXT);
        $shared = array_slice($windowTrigrams, 0, 7);
        $othersText = 'まみむめもやゆよらりるれろわをんABCDEFGHIJKLM';
        $otherTrigrams = array_slice(SubtitleFingerprintService::generateTrigrams($othersText), 0, 13);
        SubtitleFingerprint::query()->update(['trigrams' => array_values(array_merge($shared, $otherTrigrams))]);

        $response = $this->requestMatch('dQw4w9WgXcQ', 60);

        $response->assertStatus(200)
            ->assertJsonPath('has_fingerprint', true)
            ->assertJsonPath('candidates.0.song_title', 'テスト曲A');
    }

    public function test_ignores_noise_level_similarity(): void
    {
        // 異曲ノイズ水準（0.1未満）の類似度では候補に出さない
        $this->createTargetSubtitle();
        $this->createMappedFingerprint();

        $windowTrigrams = SubtitleFingerprintService::generateTrigrams(self::LYRICS_TEXT);
        $shared = array_slice($windowTrigrams, 0, 2);
        $othersText = 'まみむめもやゆよらりるれろわをんABCDEFGHIJKLMNOPQRS';
        $otherTrigrams = array_slice(SubtitleFingerprintService::generateTrigrams($othersText), 0, 20);
        SubtitleFingerprint::query()->update(['trigrams' => array_values(array_merge($shared, $otherTrigrams))]);

        $response = $this->requestMatch('dQw4w9WgXcQ', 60);

        $response->assertStatus(200)->assertJsonPath('candidates', []);
    }

    public function test_returns_candidates_for_position(): void
    {
        $this->createTargetSubtitle();
        $song = $this->createMappedFingerprint();

        $response = $this->requestMatch('dQw4w9WgXcQ', 60);

        $response->assertStatus(200)
            ->assertJsonPath('has_subtitles', true)
            ->assertJsonPath('has_fingerprint', true)
            ->assertJsonPath('candidates.0.song_id', $song->id)
            ->assertJsonPath('candidates.0.song_title', 'テスト曲A')
            ->assertJsonPath('candidates.0.song_artist', 'テストアーティスト');
    }

    public function test_unmapped_candidate_returns_original_text(): void
    {
        // マスタ未登録の候補は正規化テキスト（小文字寄せ）ではなく元の表記を返す（#633）
        $this->createTargetSubtitle();

        Archive::factory()->create([
            'channel_id' => $this->channel->channel_id,
            'video_id' => 'abcdefghijk',
        ]);
        $tsItem = TsItem::factory()->create([
            'video_id' => 'abcdefghijk',
            'ts_num' => 120,
            'text' => 'CHE.R.RY / YUI',
            'is_display' => '1',
        ]);
        SubtitleFingerprint::create([
            'id' => Str::ulid(),
            'video_id' => 'abcdefghijk',
            'ts_item_id' => $tsItem->id,
            'start_sec' => 120,
            'duration_sec' => SubtitleFingerprintService::WINDOW_DURATION_SEC,
            'fingerprint_text' => self::LYRICS_TEXT,
            'trigrams' => SubtitleFingerprintService::generateTrigrams(self::LYRICS_TEXT),
        ]);

        $response = $this->requestMatch('dQw4w9WgXcQ', 60);

        $response->assertStatus(200)
            ->assertJsonPath('candidates.0.song_id', null)
            ->assertJsonPath('candidates.0.text', 'CHE.R.RY / YUI');
    }

    public function test_returns_no_subtitles_when_not_stored(): void
    {
        $response = $this->requestMatch('dQw4w9WgXcQ', 60);

        $response->assertStatus(200)
            ->assertJsonPath('has_subtitles', false)
            ->assertJsonPath('has_fingerprint', false)
            ->assertJsonPath('candidates', []);
    }

    public function test_returns_no_fingerprint_for_music_only_window(): void
    {
        // 窓の中が効果音アノテーションだけ → トライグラム不足で候補なし
        VideoSubtitle::create([
            'id' => Str::ulid(),
            'video_id' => 'dQw4w9WgXcQ',
            'language_code' => 'ja',
            'kind' => 'asr',
            'subtitle_data' => [
                ['start' => 60, 'duration' => 5, 'text' => '[音楽]'],
            ],
            'segment_count' => 1,
        ]);

        $response = $this->requestMatch('dQw4w9WgXcQ', 60);

        $response->assertStatus(200)
            ->assertJsonPath('has_subtitles', true)
            ->assertJsonPath('has_fingerprint', false)
            ->assertJsonPath('candidates', []);
    }

    public function test_requires_auth(): void
    {
        $response = $this->getJson('/api/extension/subtitle-matches?video_id=dQw4w9WgXcQ&sec=60');

        $response->assertStatus(401);
    }

    public function test_denied_for_other_users_channel(): void
    {
        $otherUser = User::factory()->create([
            'email_verified_at' => now(),
            'role' => User::ROLE_ADMIN,
        ]);
        $token = $otherUser->createToken('extension')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/extension/subtitle-matches?video_id=dQw4w9WgXcQ&sec=60');

        $response->assertStatus(403);
    }

    public function test_returns_404_for_unknown_video(): void
    {
        $response = $this->requestMatch('xxxxxxxxxxx', 60);

        $response->assertStatus(404);
    }

    public function test_validates_params(): void
    {
        $this->requestMatch('invalid', 60)->assertStatus(422);
        $this->requestMatch('dQw4w9WgXcQ', -1)->assertStatus(422);

        $token = $this->user->createToken('extension')->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/extension/subtitle-matches?video_id=dQw4w9WgXcQ')
            ->assertStatus(422);
    }
}
