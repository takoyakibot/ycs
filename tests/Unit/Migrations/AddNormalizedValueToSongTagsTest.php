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
}
