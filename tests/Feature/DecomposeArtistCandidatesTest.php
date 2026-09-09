<?php

namespace Tests\Feature;

use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DecomposeArtistCandidatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function callArtistCandidates(string $title)
    {
        return $this->getJson('/api/songs/decompose/artist-candidates?'.http_build_query(['title' => $title]));
    }

    public function test_returns_artist_candidates_for_matching_title(): void
    {
        Song::factory()->create(['title' => 'テスト曲', 'artist' => 'アーティストA']);
        Song::factory()->create(['title' => 'テスト曲', 'artist' => 'アーティストB']);

        $response = $this->callArtistCandidates('テスト曲');

        $response->assertOk();
        $artists = $response->json('artists');
        $this->assertCount(2, $artists);
        $this->assertContains('アーティストA', $artists);
        $this->assertContains('アーティストB', $artists);
    }

    public function test_returns_empty_for_no_match(): void
    {
        Song::factory()->create(['title' => '別の曲', 'artist' => 'アーティストA']);

        $response = $this->callArtistCandidates('存在しない曲');

        $response->assertOk();
        $this->assertEmpty($response->json('artists'));
    }

    public function test_deduplicates_artists(): void
    {
        Song::factory()->create(['title' => 'テスト曲', 'artist' => '同じアーティスト']);
        Song::factory()->create(['title' => 'ＴＥＳＴ曲', 'artist' => '同じアーティスト']);

        $response = $this->callArtistCandidates('テスト曲');

        $response->assertOk();
        $this->assertCount(1, $response->json('artists'));
        $this->assertContains('同じアーティスト', $response->json('artists'));
    }

    public function test_excludes_empty_artist(): void
    {
        Song::factory()->create(['title' => 'テスト曲', 'artist' => '']);
        Song::factory()->create(['title' => 'テスト曲', 'artist' => '有効なアーティスト']);

        $response = $this->callArtistCandidates('テスト曲');

        $response->assertOk();
        $this->assertCount(1, $response->json('artists'));
        $this->assertContains('有効なアーティスト', $response->json('artists'));
    }

    public function test_matches_by_normalized_title(): void
    {
        Song::factory()->create(['title' => 'ＴＥＳＴ　ＳＯＮＧ', 'artist' => 'テストアーティスト']);

        $response = $this->callArtistCandidates('test song');

        $response->assertOk();
        $this->assertCount(1, $response->json('artists'));
        $this->assertContains('テストアーティスト', $response->json('artists'));
    }

    public function test_matches_ignoring_spaces(): void
    {
        Song::factory()->create(['title' => 'TEST SONG', 'artist' => 'スペースあり']);

        $response = $this->callArtistCandidates('TESTSONG');

        $response->assertOk();
        $this->assertCount(1, $response->json('artists'));
        $this->assertContains('スペースあり', $response->json('artists'));
    }

    public function test_matches_when_query_has_extra_spaces(): void
    {
        Song::factory()->create(['title' => 'TESTSONG', 'artist' => 'スペースなし']);

        $response = $this->callArtistCandidates('TEST SONG');

        $response->assertOk();
        $this->assertCount(1, $response->json('artists'));
        $this->assertContains('スペースなし', $response->json('artists'));
    }

    public function test_requires_title_parameter(): void
    {
        $response = $this->getJson('/api/songs/decompose/artist-candidates');

        $response->assertStatus(422);
    }
}
