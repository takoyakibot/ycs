<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * 絵文字を含むtimestamp_song_mappingsを削除
     *
     * 以前のマイグレーション(2025_12_13)でZWJ(U+200D)が除去されたため、
     * 絵文字を含むマッピングのnormalized_textはZWJ無しの状態になっている。
     * 一方、TextNormalizerはZWJを保持するよう変更されたため、
     * refresh後のts_items.normalized_textにはZWJが含まれ、マッピングと不一致になる。
     * 取り込みフローの見直しでタイムスタンプは作り直しになるため、
     * 該当マッピングを削除して不整合を解消する。
     */
    public function up(): void
    {
        // 注意: このパターンは主要な絵文字範囲をカバーするが、
        // Unicode全絵文字を網羅するものではない（例: U+1F900-1F9FF等は未含）。
        // 削除漏れがあってもマッピング不一致が残るだけで機能上の問題はない。
        $emojiPattern = '/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{2300}-\x{23FF}\x{2B50}-\x{2B55}\x{FE00}-\x{FE0F}\x{20E3}\x{E0020}-\x{E007F}]/u';

        $deletedCount = 0;

        DB::table('timestamp_song_mappings')->orderBy('id')->chunk(500, function ($mappings) use ($emojiPattern, &$deletedCount) {
            $idsToDelete = [];
            foreach ($mappings as $mapping) {
                if (preg_match($emojiPattern, $mapping->normalized_text)) {
                    Log::info('[Migration] Deleting emoji mapping', [
                        'id' => $mapping->id,
                        'normalized_text' => $mapping->normalized_text,
                        'song_id' => $mapping->song_id,
                    ]);
                    $idsToDelete[] = $mapping->id;
                }
            }
            if (! empty($idsToDelete)) {
                DB::table('timestamp_song_mappings')->whereIn('id', $idsToDelete)->delete();
                $deletedCount += count($idsToDelete);
            }
        });

        Log::info("[Migration] Deleted {$deletedCount} emoji-containing timestamp_song_mappings");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Log::warning('[Migration] Rollback not supported for emoji mapping deletion');
    }
};
