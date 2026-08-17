<?php

namespace Tests\Unit\Migrations;

use App\Helpers\TextNormalizer;
use App\Models\Song;
use App\Models\TimestampDecomposition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 無視キーワードの誤判定で自動確定されたレコードを再判定するマイグレーション
 *
 * @see database/migrations/2026_08_17_000001_redetect_auto_matched_decompositions.php
 */
class RedetectAutoMatchedDecompositionsTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_08_17_000001_redetect_auto_matched_decompositions.php');
    }

    private function createAutoMatched(string $text, array $parts, array $attributes = []): TimestampDecomposition
    {
        return TimestampDecomposition::create(array_merge([
            'id' => (string) Str::ulid(),
            'normalized_text' => TextNormalizer::normalize($text),
            'original_text' => $text,
            'parts' => $parts,
            'separator_count' => count($parts) - 1,
            'status' => TimestampDecomposition::STATUS_AUTO_MATCHED,
            'title_part_index' => 0,
            'derived_title' => $parts[0],
            'artist_part_index' => null,
            'derived_artist' => null,
            'confidence' => 0.8,
        ], $attributes));
    }

    /**
     * アーティスト名が無視キーワードの部分一致で捨てられていたレコードが、
     * 手動選別（pending）に戻ること
     *
     * アーティストが候補として戻ると候補が2つになり、どちらが曲名か
     * 自動では決められないため、自動確定を取り消して人の判断に回す
     */
    public function test_redetects_decomposition_whose_artist_was_dropped(): void
    {
        $decomposition = $this->createAutoMatched(
            'Pretender / Official髭男dism',
            ['Pretender', 'Official髭男dism']
        );

        $this->migration()->up();

        $decomposition->refresh();

        $this->assertEquals(TimestampDecomposition::STATUS_PENDING, $decomposition->status);
        $this->assertNull($decomposition->title_part_index);
        $this->assertNull($decomposition->artist_part_index);
        $this->assertNull($decomposition->derived_title);
        $this->assertNull($decomposition->derived_artist);
    }

    /**
     * 候補が3つ以上になるものも手動選別に戻ること
     */
    public function test_returns_to_pending_when_no_longer_auto_selectable(): void
    {
        $decomposition = $this->createAutoMatched(
            '曲名 / アーティストA / アーティストB',
            ['曲名', 'アーティストA', 'アーティストB']
        );

        $this->migration()->up();

        $decomposition->refresh();

        $this->assertEquals(TimestampDecomposition::STATUS_PENDING, $decomposition->status);
        $this->assertNull($decomposition->title_part_index);
        $this->assertNull($decomposition->artist_part_index);
        $this->assertNull($decomposition->derived_title);
        $this->assertNull($decomposition->derived_artist);
    }

    /**
     * 補足だけのパーツを除いて候補が1つになるものは、これまで通り維持されること
     */
    public function test_keeps_auto_matched_when_only_one_candidate_remains(): void
    {
        $decomposition = $this->createAutoMatched(
            '曲名 / cover',
            ['曲名', 'cover']
        );

        $this->migration()->up();

        $decomposition->refresh();

        $this->assertEquals(TimestampDecomposition::STATUS_AUTO_MATCHED, $decomposition->status);
        $this->assertEquals('曲名', $decomposition->derived_title);
        $this->assertNull($decomposition->derived_artist);
    }

    /**
     * 楽曲マスタに紐付け済みのレコードは対象外であること
     *
     * 既に作られてしまったアーティスト名が空の楽曲マスタの扱いは Issue #639 で扱う
     */
    public function test_skips_decompositions_already_linked_to_song(): void
    {
        $song = Song::factory()->create();

        $decomposition = $this->createAutoMatched(
            'Pretender / Official髭男dism',
            ['Pretender', 'Official髭男dism'],
            ['song_id' => $song->id]
        );

        $this->migration()->up();

        $decomposition->refresh();

        $this->assertEquals(TimestampDecomposition::STATUS_AUTO_MATCHED, $decomposition->status);
        $this->assertEquals('Pretender', $decomposition->derived_title);
        $this->assertNull($decomposition->derived_artist);
    }

    /**
     * アーティストを選別済みのレコードは触らないこと
     */
    public function test_skips_decompositions_with_artist_already_selected(): void
    {
        $decomposition = $this->createAutoMatched(
            '曲名 / アーティストA / アーティストB',
            ['曲名', 'アーティストA', 'アーティストB'],
            ['artist_part_index' => 1, 'derived_artist' => 'アーティストA']
        );

        $this->migration()->up();

        $decomposition->refresh();

        $this->assertEquals(TimestampDecomposition::STATUS_AUTO_MATCHED, $decomposition->status);
        $this->assertEquals('アーティストA', $decomposition->derived_artist);
    }

    /**
     * 何度実行しても結果が変わらないこと
     */
    public function test_is_idempotent(): void
    {
        $decomposition = $this->createAutoMatched(
            'Pretender / Official髭男dism',
            ['Pretender', 'Official髭男dism']
        );

        $this->migration()->up();
        $first = $decomposition->fresh()->only(['status', 'derived_title', 'derived_artist', 'artist_part_index']);

        $this->migration()->up();
        $second = $decomposition->fresh()->only(['status', 'derived_title', 'derived_artist', 'artist_part_index']);

        $this->assertEquals($first, $second);
    }
}
