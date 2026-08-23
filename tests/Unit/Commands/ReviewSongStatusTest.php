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

        $this->artisan('songs:review-status')->assertSuccessful();

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

        $this->artisan('songs:review-status')->assertSuccessful();

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

        $this->artisan('songs:review-status')->assertSuccessful();

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

        $this->artisan('songs:review-status', ['--dry-run' => true])->assertSuccessful();

        $song->refresh();
        $this->assertEquals('safe', $song->review_status);
    }
}
