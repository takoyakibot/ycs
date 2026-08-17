<?php

namespace Tests\Feature;

use App\Helpers\TextNormalizer;
use App\Http\Requests\MatchCandidatesRequest;
use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SongMatchCandidatesApiTest extends TestCase
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

    public function test_match_candidates_requires_authentication(): void
    {
        $response = $this->postJson(route('songs.matchCandidates'), [
            'normalized_texts' => ['ロキ'],
        ]);

        $response->assertStatus(401);
    }

    public function test_match_candidates_returns_candidate_for_decorated_text(): void
    {
        $song = Song::factory()->create(['title' => 'ロキ', 'artist' => 'みきとP']);

        $normalizedText = TextNormalizer::normalize('♪01.ロキ/みきとP');

        $response = $this->actingAs($this->user)->postJson(route('songs.matchCandidates'), [
            'normalized_texts' => [$normalizedText],
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'candidates',
            'auto_link_threshold',
            'candidate_threshold',
        ]);

        // normalized_text には "." や "/" が含まれるため、
        // ドット記法のパス指定ではなく配列として取得する
        $candidates = $response->json('candidates');
        $this->assertArrayHasKey($normalizedText, $candidates);

        $matches = $candidates[$normalizedText];
        $this->assertNotEmpty($matches);
        $this->assertEquals($song->id, $matches[0]['song_id']);
        $this->assertEquals('ロキ', $matches[0]['title']);
        $this->assertTrue($matches[0]['artist_hit']);
    }

    public function test_match_candidates_omits_texts_without_candidates(): void
    {
        Song::factory()->create(['title' => 'ロキ', 'artist' => 'みきとP']);

        $response = $this->actingAs($this->user)->postJson(route('songs.matchCandidates'), [
            'normalized_texts' => ['まったく関係のない文字列'],
        ]);

        $response->assertStatus(200);
        $this->assertSame([], $response->json('candidates'));
    }

    public function test_match_candidates_excludes_low_confidence_candidates(): void
    {
        // 1文字ずつ短いタイトルは信頼度が閾値に届かないため候補に含めない
        Song::factory()->create(['title' => '夜', 'artist' => 'ヨルシカ']);

        $response = $this->actingAs($this->user)->postJson(route('songs.matchCandidates'), [
            'normalized_texts' => ['夜に駆ける / yoasobi'],
        ]);

        $response->assertStatus(200);
        $this->assertSame([], $response->json('candidates'));
    }

    public function test_match_candidates_handles_multiple_texts(): void
    {
        Song::factory()->create(['title' => 'ロキ', 'artist' => 'みきとP']);
        Song::factory()->create(['title' => '愛して愛して愛して', 'artist' => 'きくお']);

        $response = $this->actingAs($this->user)->postJson(route('songs.matchCandidates'), [
            'normalized_texts' => ['♪ロキ / みきとp', '愛して愛して愛して', '該当なし'],
        ]);

        $response->assertStatus(200);
        $candidates = $response->json('candidates');

        $this->assertCount(2, $candidates);
        $this->assertArrayHasKey('♪ロキ / みきとp', $candidates);
        $this->assertArrayHasKey('愛して愛して愛して', $candidates);
    }

    public function test_match_candidates_validates_empty_input(): void
    {
        $response = $this->actingAs($this->user)->postJson(route('songs.matchCandidates'), [
            'normalized_texts' => [],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('normalized_texts');
    }

    public function test_match_candidates_rejects_too_many_texts(): void
    {
        $texts = array_fill(0, MatchCandidatesRequest::MAX_TEXTS + 1, 'ロキ');

        $response = $this->actingAs($this->user)->postJson(route('songs.matchCandidates'), [
            'normalized_texts' => $texts,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('normalized_texts');
    }
}
