<?php

namespace Tests\Unit\Commands;

use App\Models\Song;
use App\Models\TimestampSongMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReviewSongStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_safe_when_normalized_title_matches_mapped_text(): void
    {
        $song = Song::factory()->withoutSpotify()->create([
            'title' => 'テスト曲名',
            'artist' => 'テストアーティスト',
        ]);

        TimestampSongMapping::create([
            'id' => Str::ulid(),
            'normalized_text' => $song->normalized_title,
            'song_id' => $song->id,
        ]);

        $this->artisan('songs:review-status')->assertSuccessful();

        $song->refresh();
        $this->assertEquals('safe', $song->review_status);
    }

    public function test_needs_review_when_no_mappings(): void
    {
        $song = Song::factory()->withoutSpotify()->create([
            'title' => '孤立した曲',
            'artist' => 'アーティスト',
            'review_status' => 'safe',
        ]);

        $this->artisan('songs:review-status', ['--all' => true])->assertSuccessful();

        $song->refresh();
        $this->assertEquals('needs_review', $song->review_status);
    }

    public function test_needs_review_when_title_does_not_match(): void
    {
        $song = Song::factory()->withoutSpotify()->create([
            'title' => 'Song Title in English',
            'artist' => 'English Artist',
            'review_status' => 'safe',
        ]);

        TimestampSongMapping::create([
            'id' => Str::ulid(),
            'normalized_text' => '全く違うテキスト',
            'song_id' => $song->id,
        ]);

        $this->artisan('songs:review-status', ['--all' => true])->assertSuccessful();

        $song->refresh();
        $this->assertEquals('needs_review', $song->review_status);
    }

    public function test_needs_review_when_decoration_detected(): void
    {
        config(['strip_pattern_templates' => [
            ['label' => 'test', 'pattern' => '/【.*?】/u', 'is_regex' => true],
        ]]);

        $song = Song::factory()->withoutSpotify()->create([
            'title' => '【MV】テスト曲名',
            'artist' => 'テストアーティスト',
            'review_status' => 'safe',
        ]);

        TimestampSongMapping::create([
            'id' => Str::ulid(),
            'normalized_text' => $song->normalized_title,
            'song_id' => $song->id,
        ]);

        $this->artisan('songs:review-status', ['--all' => true])->assertSuccessful();

        $song->refresh();
        $this->assertEquals('needs_review', $song->review_status);
    }

    public function test_dry_run_does_not_update(): void
    {
        $song = Song::factory()->withoutSpotify()->create([
            'title' => 'テスト曲名',
            'artist' => 'テストアーティスト',
            'review_status' => 'safe',
        ]);

        $this->artisan('songs:review-status', ['--dry-run' => true, '--all' => true])->assertSuccessful();

        $song->refresh();
        $this->assertEquals('safe', $song->review_status);
    }

    public function test_skips_already_evaluated_records_by_default(): void
    {
        $evaluated = Song::factory()->withoutSpotify()->create([
            'title' => '判定済み曲',
            'artist' => 'アーティスト',
            'review_status' => 'safe',
        ]);

        $unevaluated = Song::factory()->withoutSpotify()->create([
            'title' => '未判定曲',
            'artist' => 'アーティスト',
            'review_status' => null,
        ]);

        TimestampSongMapping::create([
            'id' => Str::ulid(),
            'normalized_text' => $unevaluated->normalized_title,
            'song_id' => $unevaluated->id,
        ]);

        $this->artisan('songs:review-status')->assertSuccessful();

        $evaluated->refresh();
        $this->assertEquals('safe', $evaluated->review_status);

        $unevaluated->refresh();
        $this->assertEquals('safe', $unevaluated->review_status);
    }

    public function test_all_option_reevaluates_existing_records(): void
    {
        $song = Song::factory()->withoutSpotify()->create([
            'title' => '孤立した曲',
            'artist' => 'アーティスト',
            'review_status' => 'safe',
        ]);

        $this->artisan('songs:review-status', ['--all' => true])->assertSuccessful();

        $song->refresh();
        $this->assertEquals('needs_review', $song->review_status);
    }

    public function test_new_song_starts_with_null_review_status(): void
    {
        $song = Song::factory()->withoutSpotify()->create([
            'title' => '新規曲',
            'artist' => 'アーティスト',
        ]);

        $this->assertNull($song->review_status);
    }

    public function test_title_change_resets_review_status_to_null(): void
    {
        $song = Song::factory()->withoutSpotify()->create([
            'title' => '元のタイトル',
            'artist' => 'アーティスト',
            'review_status' => 'safe',
        ]);

        $song->update(['title' => '変更後のタイトル']);

        $song->refresh();
        $this->assertNull($song->review_status);
    }

    public function test_artist_change_resets_review_status_to_null(): void
    {
        $song = Song::factory()->withoutSpotify()->create([
            'title' => 'タイトル',
            'artist' => '元のアーティスト',
            'review_status' => 'safe',
        ]);

        $song->update(['artist' => '変更後のアーティスト']);

        $song->refresh();
        $this->assertNull($song->review_status);
    }

    public function test_non_title_artist_change_preserves_review_status(): void
    {
        $song = Song::factory()->withoutSpotify()->create([
            'title' => 'タイトル',
            'artist' => 'アーティスト',
            'review_status' => 'safe',
        ]);

        $song->update(['video_url' => 'https://example.com/new']);

        $song->refresh();
        $this->assertEquals('safe', $song->review_status);
    }
}
