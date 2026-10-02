<?php

namespace Tests\Unit\Migrations;

use App\Helpers\TextNormalizer;
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
