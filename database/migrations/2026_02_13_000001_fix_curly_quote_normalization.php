<?php

use App\Helpers\TextNormalizer;
use App\Models\Song;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * カーリークォート（U+2018/U+2019/U+201C/U+201D）の正規化修正を反映し、
     * 既存データの normalized_text / normalized_title / normalized_artist を再計算する。
     * 正規化修正により重複が発生する楽曲・マッピングを統合する。
     */
    public function up(): void
    {
        try {
            $this->renormalizeTsItems();
        } catch (\Exception $e) {
            Log::error('[Migration] fix_curly_quote: Failed to renormalize ts_items', ['error' => $e->getMessage()]);
            throw $e;
        }

        try {
            $this->renormalizeSongs();
        } catch (\Exception $e) {
            Log::error('[Migration] fix_curly_quote: Failed to renormalize songs', ['error' => $e->getMessage()]);
            throw $e;
        }

        try {
            $this->renormalizeTimestampSongMappings();
        } catch (\Exception $e) {
            Log::error('[Migration] fix_curly_quote: Failed to renormalize timestamp_song_mappings', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * ts_items の normalized_text を再正規化
     */
    private function renormalizeTsItems(): void
    {
        $updated = 0;

        TsItem::whereNotNull('text')
            ->where('text', '!=', '')
            ->chunk(500, function ($tsItems) use (&$updated) {
                foreach ($tsItems as $tsItem) {
                    $rawText = $tsItem->getAttributes()['text'] ?? null;
                    if ($rawText === null) {
                        continue;
                    }

                    $originalNormalized = $tsItem->getAttributes()['normalized_text'] ?? null;
                    $newNormalized = TextNormalizer::normalize($rawText);

                    if ($newNormalized === '' && $rawText !== null && trim($rawText) !== '') {
                        $newNormalized = mb_strtolower(trim($rawText), 'UTF-8');
                    }

                    if ($originalNormalized !== $newNormalized) {
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

        Log::info('[Migration] fix_curly_quote: Renormalized ts_items', ['updated_count' => $updated]);
    }

    /**
     * songs の normalized_title / normalized_artist を再正規化し、重複を統合
     */
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

            $newNormalizedTitle = TextNormalizer::normalize($song->title);
            $newNormalizedArtist = TextNormalizer::normalize($song->artist);

            // 正規化後に同じタイトル・アーティストとなる既存楽曲を検索
            $existingSong = Song::where('normalized_title', $newNormalizedTitle)
                ->where('normalized_artist', $newNormalizedArtist)
                ->where('id', '!=', $song->id)
                ->first();

            if ($existingSong) {
                $this->mergeSongs($song, $existingSong);
                $merged++;
            } else {
                $changed = $song->normalized_title !== $newNormalizedTitle
                    || $song->normalized_artist !== $newNormalizedArtist;

                if ($changed) {
                    DB::table('songs')
                        ->where('id', $song->id)
                        ->update([
                            'normalized_title' => $newNormalizedTitle,
                            'normalized_artist' => $newNormalizedArtist,
                            'updated_at' => now(),
                        ]);
                    $updated++;
                }
            }
        }

        Log::info('[Migration] fix_curly_quote: Renormalized songs', [
            'updated_count' => $updated,
            'merged_count' => $merged,
        ]);
    }

    /**
     * 2つの楽曲を統合（Spotify Track IDあり → 手動登録 → 作成日時が古い方を優先）
     */
    private function mergeSongs(Song $song, Song $existingSong): void
    {
        // 残す方を決定: Spotify Track IDがある方を優先、なければ作成日時が古い方を優先
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

        // timestamp_song_mappings の song_id 参照を移行
        TimestampSongMapping::where('song_id', $deleteSong->id)
            ->update(['song_id' => $keepSong->id]);

        // ts_items の song_id 参照を移行
        DB::table('ts_items')
            ->where('song_id', $deleteSong->id)
            ->update(['song_id' => $keepSong->id]);

        // timestamp_decompositions の song_id 参照を移行
        DB::table('timestamp_decompositions')
            ->where('song_id', $deleteSong->id)
            ->update(['song_id' => $keepSong->id]);

        Log::info('[Migration] fix_curly_quote: Merged duplicate song', [
            'kept_id' => $keepSong->id,
            'kept_title' => $keepSong->title,
            'deleted_id' => $deleteSong->id,
            'deleted_title' => $deleteSong->title,
        ]);

        $deleteSong->delete();
    }

    /**
     * timestamp_song_mappings の normalized_text を再正規化し、重複を統合
     */
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

            $originalNormalized = $mapping->normalized_text;
            $newNormalized = TextNormalizer::normalize($originalNormalized);

            if ($originalNormalized === $newNormalized) {
                continue;
            }

            // 同じ normalized_text のマッピングが既に存在するか確認
            $existingMapping = TimestampSongMapping::where('normalized_text', $newNormalized)
                ->where('id', '!=', $mapping->id)
                ->first();

            if ($existingMapping) {
                $this->mergeMappings($mapping, $existingMapping);
                $merged++;
            } else {
                $mapping->normalized_text = $newNormalized;
                $mapping->saveQuietly();
                $updated++;
            }
        }

        Log::info('[Migration] fix_curly_quote: Renormalized timestamp_song_mappings', [
            'updated_count' => $updated,
            'merged_count' => $merged,
        ]);
    }

    /**
     * 2つのマッピングを統合（手動マッピングを優先）
     */
    private function mergeMappings(
        TimestampSongMapping $mapping,
        TimestampSongMapping $existingMapping
    ): void {
        $shouldUpdateExisting = false;

        if ($mapping->is_manual && ! $existingMapping->is_manual) {
            $shouldUpdateExisting = true;
        } elseif ($mapping->is_manual === $existingMapping->is_manual) {
            if (($mapping->confidence ?? 0) > ($existingMapping->confidence ?? 0)) {
                $shouldUpdateExisting = true;
            }
        }

        if ($shouldUpdateExisting) {
            $existingMapping->update([
                'song_id' => $mapping->song_id,
                'is_manual' => $mapping->is_manual,
                'is_not_song' => $mapping->is_not_song,
                'confidence' => $mapping->confidence,
                'status' => $mapping->status,
            ]);
        }

        $mapping->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Log::warning('[Migration] Rollback not supported for curly quote normalization fix');
    }
};
