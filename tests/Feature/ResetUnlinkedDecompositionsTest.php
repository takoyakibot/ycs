<?php

namespace Tests\Feature;

use App\Helpers\TextNormalizer;
use App\Models\Song;
use App\Models\TimestampDecomposition;
use App\Models\TimestampSongMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResetUnlinkedDecompositionsTest extends TestCase
{
    use RefreshDatabase;

    private function createDecomposition(string $text, string $status, ?string $songId = null): TimestampDecomposition
    {
        return TimestampDecomposition::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize($text),
            'original_text' => $text,
            'parts' => explode('/', $text),
            'separator_count' => substr_count($text, '/'),
            'status' => $status,
            'confidence' => 0.5,
            'song_id' => $songId,
        ]);
    }

    public function test_dry_run_shows_targets_without_deleting(): void
    {
        $this->createDecomposition('曲名/アーティスト', TimestampDecomposition::STATUS_SELECTED);
        $this->createDecomposition('曲名2/アーティスト2', TimestampDecomposition::STATUS_SKIPPED);

        $this->artisan('ts-decompositions:reset-unlinked')
            ->assertSuccessful()
            ->expectsOutputToContain('ドライラン');

        $this->assertDatabaseCount('timestamp_decompositions', 2);
    }

    public function test_apply_deletes_unlinked_decompositions(): void
    {
        $this->createDecomposition('曲名/アーティスト', TimestampDecomposition::STATUS_SELECTED);
        $this->createDecomposition('曲名2/アーティスト2', TimestampDecomposition::STATUS_SKIPPED);
        $this->createDecomposition('曲名3/アーティスト3', TimestampDecomposition::STATUS_AUTO_MATCHED);

        $this->artisan('ts-decompositions:reset-unlinked', ['--apply' => true, '--skip-rescan' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('削除完了');

        $this->assertDatabaseCount('timestamp_decompositions', 0);
    }

    public function test_preserves_confirmed_linked_decompositions(): void
    {
        $song = Song::factory()->create();

        $linked = $this->createDecomposition('紐付済/アーティスト', TimestampDecomposition::STATUS_SELECTED, $song->id);
        TimestampSongMapping::factory()->withSong($song)->manual()->create([
            'normalized_text' => TextNormalizer::normalize('紐付済/アーティスト'),
        ]);

        $unlinked = $this->createDecomposition('未紐付/アーティスト', TimestampDecomposition::STATUS_SELECTED);

        $this->artisan('ts-decompositions:reset-unlinked', ['--apply' => true, '--skip-rescan' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('timestamp_decompositions', ['id' => $linked->id]);
        $this->assertDatabaseMissing('timestamp_decompositions', ['id' => $unlinked->id]);
    }

    public function test_skips_pending_decompositions(): void
    {
        $pending = $this->createDecomposition('ペンディング/アーティスト', TimestampDecomposition::STATUS_PENDING);
        $selected = $this->createDecomposition('選択済/アーティスト', TimestampDecomposition::STATUS_SELECTED);

        $this->artisan('ts-decompositions:reset-unlinked', ['--apply' => true, '--skip-rescan' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('timestamp_decompositions', ['id' => $pending->id]);
        $this->assertDatabaseMissing('timestamp_decompositions', ['id' => $selected->id]);
    }

    public function test_no_targets_shows_message(): void
    {
        $this->artisan('ts-decompositions:reset-unlinked')
            ->assertSuccessful()
            ->expectsOutputToContain('リセット対象のレコードはありません');
    }

    public function test_auto_linked_not_confirmed_is_reset(): void
    {
        $song = Song::factory()->create();

        $autoLinked = $this->createDecomposition('自動/アーティスト', TimestampDecomposition::STATUS_AUTO_MATCHED, $song->id);
        TimestampSongMapping::factory()->withSong($song)->automatic()->create([
            'normalized_text' => TextNormalizer::normalize('自動/アーティスト'),
        ]);

        $this->artisan('ts-decompositions:reset-unlinked', ['--apply' => true, '--skip-rescan' => true])
            ->assertSuccessful();

        // auto-linked (is_manual=false) は confirmed ではないのでリセット対象
        $this->assertDatabaseMissing('timestamp_decompositions', ['id' => $autoLinked->id]);
    }
}
