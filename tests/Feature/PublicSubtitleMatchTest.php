<?php

namespace Tests\Feature;

use App\Models\Archive;
use App\Models\Channel;
use App\Models\Song;
use App\Models\SubtitleFingerprint;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use App\Models\User;
use App\Services\SubtitleFingerprintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 公開字幕照合API（#632 Phase 1）
 */
class PublicSubtitleMatchTest extends TestCase
{
    use RefreshDatabase;

    private const LYRICS_TEXT = 'あいうえおかきくけこさしすせそたちつてとなにぬねの';

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $channel = Channel::factory()->create(['user_id' => $user->id]);

        Archive::factory()->create([
            'channel_id' => $channel->channel_id,
            'video_id' => 'abcdefghijk',
        ]);
    }

    private function createMappedFingerprint(): Song
    {
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

    public function test_returns_candidates_without_auth(): void
    {
        $song = $this->createMappedFingerprint();

        $response = $this->postJson('/api/public/subtitle-matches', [
            'subtitle_text' => self::LYRICS_TEXT,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('candidates.0.song_id', $song->id)
            ->assertJsonPath('candidates.0.song_title', 'テスト曲A')
            ->assertJsonPath('candidates.0.song_artist', 'テストアーティスト')
            ->assertJsonPath('candidates.0.match_count', 1);

        $response->assertJsonMissingPath('candidates.0.matches');
        $response->assertJsonMissingPath('candidates.0.normalized_text');
        $response->assertJsonMissingPath('candidates.0.source');
    }

    public function test_returns_empty_for_short_text(): void
    {
        $this->createMappedFingerprint();

        $response = $this->postJson('/api/public/subtitle-matches', [
            'subtitle_text' => 'あいう',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('candidates', [])
            ->assertJsonStructure(['trigram_count', 'message']);
    }

    public function test_returns_empty_for_no_match(): void
    {
        $this->createMappedFingerprint();

        $response = $this->postJson('/api/public/subtitle-matches', [
            'subtitle_text' => 'まみむめもやゆよらりるれろわをんがぎぐげござじずぜぞ',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('candidates', []);
    }

    public function test_validates_subtitle_text_required(): void
    {
        $response = $this->postJson('/api/public/subtitle-matches', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('subtitle_text');
    }

    public function test_validates_subtitle_text_max_length(): void
    {
        $response = $this->postJson('/api/public/subtitle-matches', [
            'subtitle_text' => str_repeat('あ', 2001),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('subtitle_text');
    }

    public function test_validates_duration_sec(): void
    {
        $response = $this->postJson('/api/public/subtitle-matches', [
            'subtitle_text' => self::LYRICS_TEXT,
            'duration_sec' => 30,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('duration_sec');
    }

    public function test_accepts_valid_duration_sec(): void
    {
        $this->createMappedFingerprint();

        $response = $this->postJson('/api/public/subtitle-matches', [
            'subtitle_text' => self::LYRICS_TEXT,
            'duration_sec' => 60,
        ]);

        $response->assertStatus(200);
    }

    public function test_validates_threshold(): void
    {
        $response = $this->postJson('/api/public/subtitle-matches', [
            'subtitle_text' => self::LYRICS_TEXT,
            'threshold' => 0,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('threshold');

        $response = $this->postJson('/api/public/subtitle-matches', [
            'subtitle_text' => self::LYRICS_TEXT,
            'threshold' => 1.5,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('threshold');
    }

    public function test_accepts_custom_threshold(): void
    {
        $this->createMappedFingerprint();

        $response = $this->postJson('/api/public/subtitle-matches', [
            'subtitle_text' => self::LYRICS_TEXT,
            'threshold' => 0.05,
        ]);

        $response->assertStatus(200);
    }

    public function test_normalizes_text_server_side(): void
    {
        $song = $this->createMappedFingerprint();

        $response = $this->postJson('/api/public/subtitle-matches', [
            'subtitle_text' => '  あいうえお かきくけこ、さしすせそ。たちつてと！なにぬねの  ',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('candidates.0.song_id', $song->id);
    }

    public function test_strips_sound_annotations(): void
    {
        $song = $this->createMappedFingerprint();

        $textWithAnnotations = '[音楽] '.self::LYRICS_TEXT.' [拍手]';

        $response = $this->postJson('/api/public/subtitle-matches', [
            'subtitle_text' => $textWithAnnotations,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('candidates.0.song_id', $song->id);
    }

    public function test_response_is_read_only(): void
    {
        $this->createMappedFingerprint();

        $response = $this->postJson('/api/public/subtitle-matches', [
            'subtitle_text' => self::LYRICS_TEXT,
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseCount('subtitle_fingerprints', 1);
        $this->assertDatabaseCount('video_subtitles', 0);
    }

    public function test_excludes_hidden_ts_items(): void
    {
        $tsItem = TsItem::factory()->create([
            'video_id' => 'abcdefghijk',
            'ts_num' => 120,
            'text' => '非表示の曲',
            'is_display' => '0',
        ]);

        $song = Song::factory()->create(['title' => '非表示の曲', 'artist' => 'アーティスト']);
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

        $response = $this->postJson('/api/public/subtitle-matches', [
            'subtitle_text' => self::LYRICS_TEXT,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('candidates', []);
    }

    public function test_excludes_not_song_candidates(): void
    {
        $tsItem = TsItem::factory()->create([
            'video_id' => 'abcdefghijk',
            'ts_num' => 120,
            'text' => 'トークパート',
            'is_display' => '1',
        ]);
        TimestampSongMapping::create([
            'id' => Str::ulid(),
            'normalized_text' => $tsItem->normalized_text,
            'song_id' => null,
            'is_not_song' => true,
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

        $response = $this->postJson('/api/public/subtitle-matches', [
            'subtitle_text' => self::LYRICS_TEXT,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('candidates', []);
    }
}
