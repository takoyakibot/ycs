<?php

namespace Tests\Feature;

use App\Helpers\TextNormalizer;
use App\Models\Archive;
use App\Models\TimestampDecomposition;
use App\Models\TsItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DecomposeNotSongTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function createDecomposition(string $text, string $status = TimestampDecomposition::STATUS_PENDING): TimestampDecomposition
    {
        $archive = Archive::factory()->create(['is_display' => true]);
        TsItem::factory()->create([
            'video_id' => $archive->video_id,
            'text' => $text,
            'is_display' => true,
        ]);

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

    public function test_mark_as_not_song(): void
    {
        $decomposition = $this->createDecomposition('雑談配信');

        $response = $this->postJson("/api/songs/decompose/{$decomposition->id}/not-song");

        $response->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('timestamp_decompositions', [
            'id' => $decomposition->id,
            'status' => TimestampDecomposition::STATUS_SKIPPED,
        ]);

        $this->assertDatabaseHas('timestamp_song_mappings', [
            'normalized_text' => TextNormalizer::normalize('雑談配信'),
            'is_not_song' => true,
            'is_manual' => true,
        ]);
    }

    public function test_undo_removes_not_song_flag(): void
    {
        $decomposition = $this->createDecomposition('雑談配信');
        $normalizedText = TextNormalizer::normalize('雑談配信');

        $this->postJson("/api/songs/decompose/{$decomposition->id}/not-song");

        $this->assertDatabaseHas('timestamp_song_mappings', [
            'normalized_text' => $normalizedText,
            'is_not_song' => true,
        ]);

        $response = $this->postJson("/api/songs/decompose/{$decomposition->id}/undo");

        $response->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('timestamp_decompositions', [
            'id' => $decomposition->id,
            'status' => TimestampDecomposition::STATUS_PENDING,
        ]);

        $this->assertDatabaseMissing('timestamp_song_mappings', [
            'normalized_text' => $normalizedText,
            'is_not_song' => true,
        ]);
    }

    public function test_not_song_excluded_from_next_pending(): void
    {
        $notSong = $this->createDecomposition('雑談配信');
        $song = $this->createDecomposition('曲名/アーティスト');

        $this->postJson("/api/songs/decompose/{$notSong->id}/not-song");

        $notSong->update(['status' => TimestampDecomposition::STATUS_PENDING]);

        $response = $this->getJson('/api/songs/decompose/next');

        $response->assertOk();
        $this->assertEquals($song->id, $response->json('item.id'));
    }
}
