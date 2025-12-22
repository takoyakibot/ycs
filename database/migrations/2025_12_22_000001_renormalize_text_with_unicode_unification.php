<?php

use App\Helpers\TextNormalizer;
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
     * 新しい正規化ルール（引用符・括弧・中点・追加チルダ等の統一）を適用
     */
    public function up(): void
    {
        $this->renormalizeTsItems();
        $this->renormalizeTimestampSongMappings();
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
                    $originalNormalized = $tsItem->normalized_text;
                    $newNormalized = TextNormalizer::normalize($tsItem->text);

                    if ($originalNormalized !== $newNormalized) {
                        // イベントを発火させずに直接更新
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

        Log::info('[Migration] Renormalized ts_items', ['updated_count' => $updated]);
    }

    /**
     * timestamp_song_mappings の normalized_text を再正規化し、重複を統合
     */
    private function renormalizeTimestampSongMappings(): void
    {
        $updated = 0;
        $merged = 0;

        // 全マッピングのIDを取得（chunk内で削除が発生するため）
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

        Log::info('[Migration] Renormalized timestamp_song_mappings', [
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
            ]);
        }

        $mapping->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Log::warning('[Migration] Rollback not supported for unicode normalization');
    }
};
