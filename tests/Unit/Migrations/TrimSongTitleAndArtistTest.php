<?php

namespace Tests\Unit\Migrations;

use App\Helpers\TextNormalizer;
use App\Models\Archive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TrimSongTitleAndArtistTest extends TestCase
{
    use RefreshDatabase;

    private function insertSongRaw(string $title, string $artist, ?string $id = null): string
    {
        $id = $id ?? (string) Str::ulid();
        DB::table('songs')->insert([
            'id' => $id,
            'title' => $title,
            'artist' => $artist,
            'normalized_title' => TextNormalizer::normalize($title),
            'normalized_artist' => TextNormalizer::normalize($artist),
            'title_comparison_key' => TextNormalizer::toComparisonKey(TextNormalizer::normalize($title)) ?: null,
            'artist_comparison_key' => TextNormalizer::toComparisonKey(TextNormalizer::normalize($artist)) ?: null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function runMigration(): void
    {
        $migration = require database_path('migrations/2026_10_02_000001_trim_song_title_and_artist.php');
        $migration->up();
    }

    public function test_simple_trim_no_collision(): void
    {
        $id = $this->insertSongRaw(' テスト曲 ', ' アーティスト ');

        $this->runMigration();

        $song = DB::table('songs')->where('id', $id)->first();
        $this->assertSame('テスト曲', $song->title);
        $this->assertSame('アーティスト', $song->artist);
    }

    public function test_trimmed_collides_with_existing(): void
    {
        $targetId = $this->insertSongRaw('テスト曲', 'アーティスト');
        $sourceId = $this->insertSongRaw('テスト曲 ', 'アーティスト');

        DB::table('timestamp_song_mappings')->insert([
            'id' => (string) Str::ulid(),
            'song_id' => $sourceId,
            'normalized_text' => 'source-mapping',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runMigration();

        $this->assertNull(DB::table('songs')->where('id', $sourceId)->first());
        $this->assertNotNull(DB::table('songs')->where('id', $targetId)->first());

        $this->assertSame(1, DB::table('timestamp_song_mappings')
            ->where('song_id', $targetId)
            ->where('normalized_text', 'source-mapping')
            ->count());
    }

    public function test_both_untrimmed_collision(): void
    {
        $id1 = $this->insertSongRaw('テスト曲 ', 'アーティスト');
        $id2 = $this->insertSongRaw(' テスト曲', ' アーティスト');

        $this->runMigration();

        $remaining = DB::table('songs')
            ->whereIn('id', [$id1, $id2])
            ->get();
        $this->assertCount(1, $remaining);

        $kept = $remaining->first();
        $this->assertSame('テスト曲', $kept->title);
        $this->assertSame('アーティスト', $kept->artist);
    }

    public function test_merge_transfers_tags(): void
    {
        $targetId = $this->insertSongRaw('テスト曲', 'A');
        $sourceId = $this->insertSongRaw('テスト曲 ', 'A');

        DB::table('song_tags')->insert([
            'id' => (string) Str::ulid(),
            'song_id' => $sourceId,
            'value' => 'タグ1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runMigration();

        $this->assertSame(0, DB::table('song_tags')->where('song_id', $sourceId)->count());
        $this->assertSame(1, DB::table('song_tags')
            ->where('song_id', $targetId)
            ->where('value', 'タグ1')
            ->count());
    }

    public function test_merge_skips_duplicate_tags(): void
    {
        $targetId = $this->insertSongRaw('テスト曲', 'A');
        $sourceId = $this->insertSongRaw('テスト曲 ', 'A');

        DB::table('song_tags')->insert([
            ['id' => (string) Str::ulid(), 'song_id' => $targetId, 'value' => '共通タグ', 'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) Str::ulid(), 'song_id' => $sourceId, 'value' => '共通タグ', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->runMigration();

        $this->assertSame(1, DB::table('song_tags')
            ->where('song_id', $targetId)
            ->where('value', '共通タグ')
            ->count());
    }

    public function test_merge_transfers_ts_items_and_decompositions(): void
    {
        $targetId = $this->insertSongRaw('テスト曲', 'A');
        $sourceId = $this->insertSongRaw('テスト曲 ', 'A');

        $archive = Archive::factory()->create();
        DB::table('ts_items')->insert([
            'id' => (string) Str::uuid(),
            'video_id' => $archive->video_id,
            'type' => '1',
            'ts_text' => '0:00',
            'ts_num' => 0,
            'text' => 'テスト',
            'song_id' => $sourceId,
            'is_display' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('timestamp_decompositions')->insert([
            'id' => (string) Str::ulid(),
            'original_text' => 'decomp test',
            'normalized_text' => 'decomp-test',
            'parts' => '["decomp","test"]',
            'song_id' => $sourceId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runMigration();

        $this->assertSame(1, DB::table('ts_items')->where('song_id', $targetId)->count());
        $this->assertSame(0, DB::table('ts_items')->where('song_id', $sourceId)->count());
        $this->assertSame(1, DB::table('timestamp_decompositions')->where('song_id', $targetId)->count());
        $this->assertSame(0, DB::table('timestamp_decompositions')->where('song_id', $sourceId)->count());
    }

    public function test_three_way_collision_merges_to_one(): void
    {
        $id1 = $this->insertSongRaw('テスト曲', 'A');
        $id2 = $this->insertSongRaw('テスト曲 ', 'A');
        $id3 = $this->insertSongRaw(' テスト曲', ' A');

        DB::table('song_tags')->insert([
            ['id' => (string) Str::ulid(), 'song_id' => $id2, 'value' => 'タグ2', 'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) Str::ulid(), 'song_id' => $id3, 'value' => 'タグ3', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->runMigration();

        $remaining = DB::table('songs')->whereIn('id', [$id1, $id2, $id3])->get();
        $this->assertCount(1, $remaining);

        $keptId = $remaining->first()->id;
        $this->assertSame($id1, $keptId);

        $tags = DB::table('song_tags')->where('song_id', $keptId)->pluck('value')->sort()->values()->toArray();
        $this->assertContains('タグ2', $tags);
        $this->assertContains('タグ3', $tags);
    }

    public function test_already_trimmed_songs_are_untouched(): void
    {
        $id = $this->insertSongRaw('テスト曲', 'アーティスト');
        $originalUpdatedAt = DB::table('songs')->where('id', $id)->value('updated_at');

        $this->runMigration();

        $song = DB::table('songs')->where('id', $id)->first();
        $this->assertSame('テスト曲', $song->title);
        $this->assertSame($originalUpdatedAt, $song->updated_at);
    }

    public function test_normalized_fields_are_recalculated_after_trim(): void
    {
        $id = $this->insertSongRaw(' テスト曲 ', ' アーティスト ');

        $this->runMigration();

        $song = DB::table('songs')->where('id', $id)->first();
        $this->assertSame(TextNormalizer::normalize('テスト曲'), $song->normalized_title);
        $this->assertSame(TextNormalizer::normalize('アーティスト'), $song->normalized_artist);
    }
}
