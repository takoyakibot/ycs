<?php

namespace Tests\Unit\Models;

use App\Models\Song;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SongDefaultReviewStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_song_gets_needs_review_by_default(): void
    {
        $song = Song::factory()->withoutSpotify()->create([
            'title' => 'テスト曲',
            'artist' => 'テストアーティスト',
        ]);

        $this->assertEquals(Song::REVIEW_STATUS_NEEDS_REVIEW, $song->review_status);
    }

    public function test_explicit_review_status_is_not_overwritten(): void
    {
        $song = Song::factory()->withoutSpotify()->create([
            'title' => 'テスト曲',
            'artist' => 'テストアーティスト',
            'review_status' => Song::REVIEW_STATUS_SAFE,
        ]);

        $this->assertEquals(Song::REVIEW_STATUS_SAFE, $song->review_status);
    }
}
