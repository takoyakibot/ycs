<?php

namespace Tests\Unit\Migrations;

use App\Helpers\TextNormalizer;
use App\Models\Song;
use App\Models\TimestampDecomposition;
use App\Models\TimestampSongMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 旧ヒューリスティックで auto_matched になったレコードを pending に戻し、
 * それで作られたアーティスト空の songs / マッピングを削除するマイグレーション
 *
 * @see database/migrations/2026_08_21_000001_reset_auto_matched_decompositions_to_exact_match_only.php
 */
class ResetAutoMatchedDecompositionsToExactMatchOnlyTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_08_21_000001_reset_auto_matched_decompositions_to_exact_match_only.php');
    }

    private function createAutoMatched(string $text, array $attributes = []): TimestampDecomposition
    {
        return TimestampDecomposition::create(array_merge([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize($text),
            'original_text' => $text,
            'parts' => explode(' / ', $text),
            'separator_count' => 1,
            'status' => TimestampDecomposition::STATUS_AUTO_MATCHED,
            'title_part_index' => 0,
            'derived_title' => explode(' / ', $text)[0],
            'artist_part_index' => null,
            'derived_artist' => null,
            'confidence' => 0.8,
        ], $attributes));
    }

    /**
     * song_id が無い auto_matched レコードが pending に戻ること
     */
    public function test_resets_unlinked_auto_matched_to_pending(): void
    {
        $decomposition = $this->createAutoMatched('曲名 / cover');

        $this->migration()->up();

        $decomposition->refresh();

        $this->assertEquals(TimestampDecomposition::STATUS_PENDING, $decomposition->status);
        $this->assertNull($decomposition->derived_title);
        $this->assertNull($decomposition->title_part_index);
        $this->assertNull($decomposition->confidence);
    }

    /**
     * アーティスト名が空の songs に紐付いていた場合、マッピングと
     * その songs 行が削除され、decomposition が未紐付けの pending に戻ること
     */
    public function test_deletes_empty_artist_song_and_mapping(): void
    {
        $song = Song::factory()->create(['artist' => '']);

        $decomposition = $this->createAutoMatched('曲名 / Official髭男dism', [
            'song_id' => $song->id,
        ]);

        TimestampSongMapping::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => $decomposition->normalized_text,
            'song_id' => $song->id,
            'is_not_song' => false,
            'is_manual' => true,
            'status' => 'linked',
        ]);

        $this->migration()->up();

        $decomposition->refresh();

        $this->assertEquals(TimestampDecomposition::STATUS_PENDING, $decomposition->status);
        $this->assertNull($decomposition->song_id);
        $this->assertDatabaseMissing('songs', ['id' => $song->id]);
        $this->assertDatabaseMissing('timestamp_song_mappings', ['normalized_text' => $decomposition->normalized_text]);
    }

    /**
     * アーティスト名が入っている songs は削除しないこと
     *
     * cascadeArtistSelection が結果的に正しく推測できていたケースなど、
     * データとして正しい可能性があるため
     */
    public function test_keeps_song_with_artist(): void
    {
        $song = Song::factory()->create(['artist' => '星街すいせい']);

        $decomposition = $this->createAutoMatched('GHOST / 星街すいせい', [
            'song_id' => $song->id,
            'derived_artist' => '星街すいせい',
            'artist_part_index' => 1,
        ]);

        $this->migration()->up();

        $decomposition->refresh();

        $this->assertEquals(TimestampDecomposition::STATUS_PENDING, $decomposition->status);
        $this->assertNull($decomposition->song_id);
        $this->assertDatabaseHas('songs', ['id' => $song->id, 'artist' => '星街すいせい']);
    }

    /**
     * 空アーティストの songs が他の行からも参照されている場合は削除しないこと
     */
    public function test_keeps_empty_artist_song_when_still_referenced_elsewhere(): void
    {
        $song = Song::factory()->create(['artist' => '']);

        $decomposition = $this->createAutoMatched('曲名A / cover', [
            'song_id' => $song->id,
        ]);

        // 別のマッピングが同じ song を正当に参照している
        TimestampSongMapping::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize('別のタイムスタンプ'),
            'song_id' => $song->id,
            'is_not_song' => false,
            'is_manual' => true,
            'status' => 'linked',
        ]);

        $this->migration()->up();

        $decomposition->refresh();

        $this->assertNull($decomposition->song_id);
        $this->assertDatabaseHas('songs', ['id' => $song->id]);
    }

    /**
     * pending / selected / skipped のレコードには触らないこと
     */
    public function test_does_not_touch_other_statuses(): void
    {
        $pending = $this->createAutoMatched('保留 / アーティスト', ['status' => TimestampDecomposition::STATUS_PENDING, 'derived_title' => null, 'title_part_index' => null, 'confidence' => null]);
        $selected = $this->createAutoMatched('確定 / アーティスト', ['status' => TimestampDecomposition::STATUS_SELECTED, 'derived_artist' => 'アーティスト', 'artist_part_index' => 1]);

        $this->migration()->up();

        $this->assertEquals(TimestampDecomposition::STATUS_PENDING, $pending->fresh()->status);
        $this->assertEquals(TimestampDecomposition::STATUS_SELECTED, $selected->fresh()->status);
        $this->assertEquals('アーティスト', $selected->fresh()->derived_artist);
    }

    /**
     * 何度実行しても結果が変わらないこと
     */
    public function test_is_idempotent(): void
    {
        $song = Song::factory()->create(['artist' => '']);
        $decomposition = $this->createAutoMatched('曲名 / Official髭男dism', ['song_id' => $song->id]);
        TimestampSongMapping::create([
            'id' => (string) Str::ulid(),
            'normalized_text' => $decomposition->normalized_text,
            'song_id' => $song->id,
            'is_not_song' => false,
            'is_manual' => true,
            'status' => 'linked',
        ]);

        $this->migration()->up();
        $this->migration()->up();

        $decomposition->refresh();

        $this->assertEquals(TimestampDecomposition::STATUS_PENDING, $decomposition->status);
        $this->assertDatabaseMissing('songs', ['id' => $song->id]);
    }
}
