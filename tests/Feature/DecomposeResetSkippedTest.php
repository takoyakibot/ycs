<?php

namespace Tests\Feature;

use App\Helpers\TextNormalizer;
use App\Models\Song;
use App\Models\TimestampDecomposition;
use App\Models\TimestampSongMapping;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DecomposeResetSkippedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function createDecomposition(string $text, string $status): TimestampDecomposition
    {
        return TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize($text),
            'original_text' => $text,
            'parts' => explode('/', $text),
            'separator_count' => substr_count($text, '/'),
            'status' => $status,
            'confidence' => 0.5,
        ]);
    }

    public function test_reset_skipped_to_pending(): void
    {
        $skipped1 = $this->createDecomposition('曲名/アーティスト', TimestampDecomposition::STATUS_SKIPPED);
        $skipped2 = $this->createDecomposition('曲名2/アーティスト2', TimestampDecomposition::STATUS_SKIPPED);
        $pending = $this->createDecomposition('曲名3/アーティスト3', TimestampDecomposition::STATUS_PENDING);
        $selected = $this->createDecomposition('曲名4/アーティスト4', TimestampDecomposition::STATUS_SELECTED);

        $response = $this->postJson('/api/songs/decompose/reset-skipped');

        $response->assertOk()->assertJson(['success' => true, 'reset_count' => 2]);

        $this->assertDatabaseHas('timestamp_decompositions', ['id' => $skipped1->id, 'status' => 'pending']);
        $this->assertDatabaseHas('timestamp_decompositions', ['id' => $skipped2->id, 'status' => 'pending']);
        $this->assertDatabaseHas('timestamp_decompositions', ['id' => $pending->id, 'status' => 'pending']);
        $this->assertDatabaseHas('timestamp_decompositions', ['id' => $selected->id, 'status' => 'selected']);
    }

    public function test_excludes_not_song_from_reset(): void
    {
        $skipped = $this->createDecomposition('雑談配信', TimestampDecomposition::STATUS_SKIPPED);
        TimestampSongMapping::factory()->notSong()->create([
            'normalized_text' => TextNormalizer::normalize('雑談配信'),
        ]);

        $normalSkipped = $this->createDecomposition('曲名/アーティスト', TimestampDecomposition::STATUS_SKIPPED);

        $response = $this->postJson('/api/songs/decompose/reset-skipped');

        $response->assertOk()->assertJson(['reset_count' => 1]);

        $this->assertDatabaseHas('timestamp_decompositions', ['id' => $skipped->id, 'status' => 'skipped']);
        $this->assertDatabaseHas('timestamp_decompositions', ['id' => $normalSkipped->id, 'status' => 'pending']);
    }

    public function test_excludes_confirmed_mapping_from_reset(): void
    {
        $skipped = $this->createDecomposition('曲名/アーティスト', TimestampDecomposition::STATUS_SKIPPED);
        $song = Song::factory()->create();
        TimestampSongMapping::factory()->create([
            'normalized_text' => TextNormalizer::normalize('曲名/アーティスト'),
            'song_id' => $song->id,
            'status' => 'linked',
            'is_manual' => true,
        ]);

        $normalSkipped = $this->createDecomposition('曲名2/アーティスト2', TimestampDecomposition::STATUS_SKIPPED);

        $response = $this->postJson('/api/songs/decompose/reset-skipped');

        $response->assertOk()->assertJson(['reset_count' => 1]);

        $this->assertDatabaseHas('timestamp_decompositions', ['id' => $skipped->id, 'status' => 'skipped']);
        $this->assertDatabaseHas('timestamp_decompositions', ['id' => $normalSkipped->id, 'status' => 'pending']);
    }

    public function test_returns_zero_when_no_skipped(): void
    {
        $this->createDecomposition('曲名/アーティスト', TimestampDecomposition::STATUS_PENDING);

        $response = $this->postJson('/api/songs/decompose/reset-skipped');

        $response->assertOk()->assertJson(['reset_count' => 0]);
    }
}
