<?php

use App\Helpers\TextNormalizer;
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

        Log::info('[Migration] Renormalized ts_items (halfwidth kana)', ['updated_count' => $updated]);
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

        Log::info('[Migration] Renormalized timestamp_song_mappings (halfwidth kana)', [
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

        Log::info('[Migration] Renormalized timestamp_decompositions (halfwidth kana)', [
            'updated_count' => $updated,
            'merged_count' => $merged,
        ]);
    }

    public function down(): void
    {
        Log::warning('[Migration] Rollback not supported for halfwidth kana normalization');
    }
};
