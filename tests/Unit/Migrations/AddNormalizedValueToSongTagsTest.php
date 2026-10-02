<?php

namespace Tests\Unit\Migrations;

use App\Models\Song;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AddNormalizedValueToSongTagsTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfills_normalized_value_for_existing_tags(): void
    {
        $migration = require database_path('migrations/2026_10_03_000002_add_normalized_value_to_song_tags.php');
        $song = Song::factory()->create(['artist' => 'A']);
        $migration->down();

        $tagId = (string) Str::ulid();
        DB::table('song_tags')->insert([
            'id' => $tagId,
            'song_id' => $song->id,
            'value' => 'ＨＡＴＳＵＮＥ　ＭＩＫＵ',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        $this->assertSame('hatsune miku', DB::table('song_tags')->where('id', $tagId)->value('normalized_value'));
    }

    public function test_up_can_be_rerun_after_partial_failure(): void
    {
        $migration = require database_path('migrations/2026_10_03_000002_add_normalized_value_to_song_tags.php');
        $song = Song::factory()->create(['artist' => 'A']);
        DB::table('song_tags')->where('song_id', $song->id)->update(['normalized_value' => null]);

        // 列が既にある状態で再実行しても失敗せず、未設定の行だけ埋める
        $migration->up();

        $this->assertSame('a', DB::table('song_tags')->where('song_id', $song->id)->value('normalized_value'));
    }

    /**
     * MySQL では CHARACTER SET / COLLATE を型の直後・NULL 可否より前に置く（SQLite では実行されないため文字列で固定する）
     */
    public function test_modify_statement_places_collation_before_nullability(): void
    {
        $migration = require database_path('migrations/2026_10_03_000002_add_normalized_value_to_song_tags.php');

        $this->assertSame(
            'ALTER TABLE `song_tags` MODIFY `normalized_value` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL',
            $migration::MODIFY_STATEMENT
        );
    }
}
