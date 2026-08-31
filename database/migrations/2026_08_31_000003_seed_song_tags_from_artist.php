<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $songs = DB::table('songs')
            ->whereNotNull('artist')
            ->where('artist', '!=', '')
            ->select('id', 'artist')
            ->get();

        foreach ($songs as $song) {
            $tags = $this->splitArtistToTags($song->artist);

            foreach ($tags as $tag) {
                DB::table('song_tags')->insert([
                    'id' => (string) Str::ulid(),
                    'song_id' => $song->id,
                    'value' => $tag,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('song_tags')->truncate();
    }

    private function splitArtistToTags(string $artist): array
    {
        if ($artist === '') {
            return [];
        }

        $normalized = preg_replace('/\s+feat\.?\s+/ui', "\x00", $artist);
        $normalized = preg_replace('/\s+ft\.?\s+/ui', "\x00", $normalized);
        $normalized = str_replace(['×', '＆'], "\x00", $normalized);
        $normalized = preg_replace('/\s+x\s+/u', "\x00", $normalized);
        $normalized = str_replace(['/', '／', ',', '、', '&'], "\x00", $normalized);

        $parts = explode("\x00", $normalized);
        $parts = array_map('trim', $parts);
        $parts = array_filter($parts, fn ($p) => $p !== '');

        return array_values($parts);
    }
};
