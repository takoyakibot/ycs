<?php

use App\Helpers\TextNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $this->mergeCollisions();
        $this->trimRemaining();
    }

    private function mergeCollisions(): void
    {
        $collisionGroups = DB::select('
            SELECT TRIM(title) AS trimmed_title, TRIM(artist) AS trimmed_artist
            FROM songs
            GROUP BY TRIM(title), TRIM(artist)
            HAVING COUNT(*) > 1
        ');

        foreach ($collisionGroups as $group) {
            $songs = DB::table('songs')
                ->whereRaw('TRIM(title) = ? AND TRIM(artist) = ?', [$group->trimmed_title, $group->trimmed_artist])
                ->orderByRaw('(CASE WHEN title = TRIM(title) AND artist = TRIM(artist) THEN 0 ELSE 1 END)')
                ->orderBy('id')
                ->get();

            $keepId = $songs->first()->id;
            foreach ($songs->skip(1) as $song) {
                $this->mergeSong($song->id, $keepId);
            }
        }
    }

    private function trimRemaining(): void
    {
        $remaining = DB::select('
            SELECT id, TRIM(title) AS trimmed_title, TRIM(artist) AS trimmed_artist
            FROM songs
            WHERE title != TRIM(title) OR artist != TRIM(artist)
        ');

        foreach ($remaining as $row) {
            $normalizedTitle = TextNormalizer::normalize($row->trimmed_title);
            $normalizedArtist = TextNormalizer::normalize($row->trimmed_artist);
            DB::table('songs')->where('id', $row->id)->update([
                'title' => $row->trimmed_title,
                'artist' => $row->trimmed_artist,
                'normalized_title' => $normalizedTitle,
                'normalized_artist' => $normalizedArtist,
                'title_comparison_key' => TextNormalizer::toComparisonKey($normalizedTitle) ?: null,
                'artist_comparison_key' => TextNormalizer::toComparisonKey($normalizedArtist) ?: null,
            ]);
        }
    }

    private function mergeSong(string $sourceId, string $targetId): void
    {
        DB::transaction(function () use ($sourceId, $targetId) {
            $targetTexts = DB::table('timestamp_song_mappings')
                ->where('song_id', $targetId)
                ->pluck('normalized_text')
                ->toArray();

            if (! empty($targetTexts)) {
                DB::table('timestamp_song_mappings')
                    ->where('song_id', $sourceId)
                    ->whereIn('normalized_text', $targetTexts)
                    ->delete();
            }

            DB::table('timestamp_song_mappings')
                ->where('song_id', $sourceId)
                ->update(['song_id' => $targetId]);

            DB::table('ts_items')
                ->where('song_id', $sourceId)
                ->update(['song_id' => $targetId]);

            DB::table('timestamp_decompositions')
                ->where('song_id', $sourceId)
                ->update(['song_id' => $targetId]);

            $targetTags = DB::table('song_tags')
                ->where('song_id', $targetId)
                ->pluck('value')
                ->toArray();

            $sourceTags = DB::table('song_tags')
                ->where('song_id', $sourceId)
                ->get();

            foreach ($sourceTags as $tag) {
                if (! in_array($tag->value, $targetTags, true)) {
                    DB::table('song_tags')->insert([
                        'id' => (string) Str::ulid(),
                        'song_id' => $targetId,
                        'value' => $tag->value,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            DB::table('song_tags')->where('song_id', $sourceId)->delete();
            DB::table('songs')->where('id', $sourceId)->delete();
        });
    }

    public function down(): void
    {
        // データクレンジングのため元に戻すことはできない
    }
};
