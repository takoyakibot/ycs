<?php

use App\Helpers\TextNormalizer;
use App\Models\Song;
use App\Models\TimestampDecomposition;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        $this->renormalizeTsItems();
        $this->renormalizeTimestampSongMappings();
        $this->renormalizeTimestampDecompositions();
        $this->renormalizeSongs();
    }

    private function renormalizeTsItems(): void
    {
        $updated = 0;

        TsItem::whereNotNull('text')
            ->where('text', '!=', '')
            ->chunk(500, function ($tsItems) use (&$updated) {
                foreach ($tsItems as $tsItem) {
                    $newNormalized = TextNormalizer::normalize($tsItem->text);

                    if ($tsItem->normalized_text !== $newNormalized) {
                        DB::table('ts_items')
                            ->where('id', $tsItem->id)
                            ->update([
                                'normalized_text' => $newNormalized,
                                'updated_at' => now(),
                            ]);
                        $updated++;
                    }
                }
            });

        Log::info('[Migration] Renormalized ts_items (NFC)', ['updated_count' => $updated]);
    }

    private function renormalizeTimestampSongMappings(): void
    {
        $updated = 0;
        $merged = 0;

        $mappingIds = TimestampSongMapping::pluck('id')->toArray();

        foreach ($mappingIds as $mappingId) {
            $mapping = TimestampSongMapping::find($mappingId);
            if (! $mapping) {
                continue;
            }

            $newNormalized = TextNormalizer::normalize($mapping->normalized_text);

            if ($mapping->normalized_text === $newNormalized) {
                continue;
            }

            $existingMapping = TimestampSongMapping::where('normalized_text', $newNormalized)
                ->where('id', '!=', $mapping->id)
                ->first();

            if ($existingMapping) {
                if ($mapping->is_manual && ! $existingMapping->is_manual) {
                    $existingMapping->update([
                        'song_id' => $mapping->song_id,
                        'status' => $mapping->status,
                        'is_manual' => $mapping->is_manual,
                        'is_not_song' => $mapping->is_not_song,
                        'confidence' => $mapping->confidence,
                    ]);
                }
                $mapping->delete();
                $merged++;
            } else {
                $mapping->normalized_text = $newNormalized;
                $mapping->saveQuietly();
                $updated++;
            }
        }

        Log::info('[Migration] Renormalized timestamp_song_mappings (NFC)', [
            'updated_count' => $updated,
            'merged_count' => $merged,
        ]);
    }

    private function renormalizeTimestampDecompositions(): void
    {
        $updated = 0;
        $merged = 0;

        $decompositionIds = TimestampDecomposition::pluck('id')->toArray();

        foreach ($decompositionIds as $decompositionId) {
            $decomposition = TimestampDecomposition::find($decompositionId);
            if (! $decomposition) {
                continue;
            }

            $newNormalized = TextNormalizer::normalize($decomposition->normalized_text);

            if ($decomposition->normalized_text === $newNormalized) {
                continue;
            }

            $existing = TimestampDecomposition::where('normalized_text', $newNormalized)
                ->where('id', '!=', $decomposition->id)
                ->first();

            if ($existing) {
                $decomposition->delete();
                $merged++;
            } else {
                DB::table('timestamp_decompositions')
                    ->where('id', $decomposition->id)
                    ->update([
                        'normalized_text' => $newNormalized,
                        'updated_at' => now(),
                    ]);
                $updated++;
            }
        }

        Log::info('[Migration] Renormalized timestamp_decompositions (NFC)', [
            'updated_count' => $updated,
            'merged_count' => $merged,
        ]);
    }

    private function renormalizeSongs(): void
    {
        $updated = 0;
        $merged = 0;

        $songIds = Song::pluck('id')->toArray();

        foreach ($songIds as $songId) {
            $song = Song::find($songId);
            if (! $song) {
                continue;
            }

            $newTitle = TextNormalizer::normalize($song->title);
            $newArtist = TextNormalizer::normalize($song->artist);

            if ($song->normalized_title === $newTitle && $song->normalized_artist === $newArtist) {
                continue;
            }

            $existingSong = Song::where('normalized_title', $newTitle)
                ->where('normalized_artist', $newArtist)
                ->where('id', '!=', $song->id)
                ->first();

            if ($existingSong) {
                $this->mergeSongs($song, $existingSong);
                $merged++;
            } else {
                DB::table('songs')
                    ->where('id', $song->id)
                    ->update([
                        'normalized_title' => $newTitle,
                        'normalized_artist' => $newArtist,
                        'updated_at' => now(),
                    ]);
                $updated++;
            }
        }

        Log::info('[Migration] Renormalized songs (NFC)', [
            'updated_count' => $updated,
            'merged_count' => $merged,
        ]);
    }

    private function mergeSongs(Song $song, Song $existingSong): void
    {
        $keepSong = $existingSong;
        $deleteSong = $song;

        if ($song->spotify_track_id && ! $existingSong->spotify_track_id) {
            $keepSong = $song;
            $deleteSong = $existingSong;
        } elseif (! $song->spotify_track_id && $existingSong->spotify_track_id) {
            $keepSong = $existingSong;
            $deleteSong = $song;
        } elseif ($song->created_at < $existingSong->created_at) {
            $keepSong = $song;
            $deleteSong = $existingSong;
        }

        TimestampSongMapping::where('song_id', $deleteSong->id)
            ->update(['song_id' => $keepSong->id]);

        DB::table('ts_items')
            ->where('song_id', $deleteSong->id)
            ->update(['song_id' => $keepSong->id]);

        DB::table('timestamp_decompositions')
            ->where('song_id', $deleteSong->id)
            ->update(['song_id' => $keepSong->id]);

        Log::info('[Migration] Merged duplicate song (NFC)', [
            'kept_id' => $keepSong->id,
            'kept_title' => $keepSong->title,
            'deleted_id' => $deleteSong->id,
            'deleted_title' => $deleteSong->title,
        ]);

        $deleteSong->delete();
    }

    public function down(): void
    {
        Log::warning('[Migration] Rollback not supported for NFC normalization');
    }
};
