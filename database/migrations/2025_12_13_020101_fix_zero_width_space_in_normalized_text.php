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
     * ゼロ幅スペースのPHP正規表現パターン（U+200B, U+200C, U+200D, U+FEFF）
     *
     * 注意: このパターンにはZWJ(U+200D)が含まれているが、現在の方針ではZWJは
     * 絵文字シーケンスで使用されるため保持する方針に変更済み。
     * （参照: TextNormalizer.php の removeZeroWidthChars メソッド）
     * 本マイグレーションは適用済みのため修正不要。
     */
    private string $zeroWidthPattern = '/[\x{200B}-\x{200D}\x{FEFF}]/u';

    /**
     * Run the migrations.
     *
     * ゼロ幅スペースなどの不可視文字を除去し、重複マッピングを統合
     */
    public function up(): void
    {
        $this->fixTsItems();
        $this->fixTimestampSongMappings();
    }

    /**
     * ts_items の text と normalized_text を修正
     */
    private function fixTsItems(): void
    {
        $driver = DB::getDriverName();

        $query = TsItem::query();

        if ($driver === 'sqlite') {
            // SQLite: LIKEで検索（4種類全ての不可視文字を検索）
            $query->where(function ($q) {
                $q->where('text', 'LIKE', '%'."\xE2\x80\x8B".'%')      // U+200B
                    ->orWhere('text', 'LIKE', '%'."\xE2\x80\x8C".'%')  // U+200C
                    ->orWhere('text', 'LIKE', '%'."\xE2\x80\x8D".'%')  // U+200D
                    ->orWhere('text', 'LIKE', '%'."\xEF\xBB\xBF".'%')  // U+FEFF
                    ->orWhere('normalized_text', 'LIKE', '%'."\xE2\x80\x8B".'%')
                    ->orWhere('normalized_text', 'LIKE', '%'."\xE2\x80\x8C".'%')
                    ->orWhere('normalized_text', 'LIKE', '%'."\xE2\x80\x8D".'%')
                    ->orWhere('normalized_text', 'LIKE', '%'."\xEF\xBB\xBF".'%');
            });
        } else {
            // MySQL/MariaDB: BINARY LIKEで検索（REGEXPよりも確実）
            $query->whereRaw("
                CAST(text AS BINARY) LIKE CONCAT('%', UNHEX('E2808B'), '%')
                OR CAST(text AS BINARY) LIKE CONCAT('%', UNHEX('E2808C'), '%')
                OR CAST(text AS BINARY) LIKE CONCAT('%', UNHEX('E2808D'), '%')
                OR CAST(text AS BINARY) LIKE CONCAT('%', UNHEX('EFBBBF'), '%')
                OR CAST(normalized_text AS BINARY) LIKE CONCAT('%', UNHEX('E2808B'), '%')
                OR CAST(normalized_text AS BINARY) LIKE CONCAT('%', UNHEX('E2808C'), '%')
                OR CAST(normalized_text AS BINARY) LIKE CONCAT('%', UNHEX('E2808D'), '%')
                OR CAST(normalized_text AS BINARY) LIKE CONCAT('%', UNHEX('EFBBBF'), '%')
            ");
        }

        // メモリ効率のためchunkで処理
        $query->chunk(100, function ($tsItems) {
            foreach ($tsItems as $tsItem) {
                $this->processTsItem($tsItem);
            }
        });
    }

    /**
     * 個別のts_itemを処理
     */
    private function processTsItem(TsItem $tsItem): void
    {
        $originalText = $tsItem->text;
        $originalNormalized = $tsItem->normalized_text;

        $newText = preg_replace($this->zeroWidthPattern, '', $tsItem->text);
        $newNormalized = TextNormalizer::normalize($newText);

        if ($originalText === $newText && $originalNormalized === $newNormalized) {
            return;
        }

        // イベントを発火させずに直接更新（saving イベントとの競合を回避）
        DB::table('ts_items')
            ->where('id', $tsItem->id)
            ->update([
                'text' => $newText,
                'normalized_text' => $newNormalized,
                'updated_at' => now(),
            ]);

        Log::info('[Migration] Fixed ts_item', [
            'id' => $tsItem->id,
            'original_text' => $originalText,
            'new_text' => $newText,
        ]);
    }

    /**
     * timestamp_song_mappings の normalized_text を修正し、重複を統合
     */
    private function fixTimestampSongMappings(): void
    {
        $driver = DB::getDriverName();

        $query = TimestampSongMapping::query();

        if ($driver === 'sqlite') {
            // SQLite: LIKEで検索（4種類全ての不可視文字を検索）
            $query->where(function ($q) {
                $q->where('normalized_text', 'LIKE', '%'."\xE2\x80\x8B".'%')      // U+200B
                    ->orWhere('normalized_text', 'LIKE', '%'."\xE2\x80\x8C".'%')  // U+200C
                    ->orWhere('normalized_text', 'LIKE', '%'."\xE2\x80\x8D".'%')  // U+200D
                    ->orWhere('normalized_text', 'LIKE', '%'."\xEF\xBB\xBF".'%'); // U+FEFF
            });
        } else {
            $query->whereRaw("
                CAST(normalized_text AS BINARY) LIKE CONCAT('%', UNHEX('E2808B'), '%')
                OR CAST(normalized_text AS BINARY) LIKE CONCAT('%', UNHEX('E2808C'), '%')
                OR CAST(normalized_text AS BINARY) LIKE CONCAT('%', UNHEX('E2808D'), '%')
                OR CAST(normalized_text AS BINARY) LIKE CONCAT('%', UNHEX('EFBBBF'), '%')
            ");
        }

        // chunk内で削除が発生するため、IDを先に取得
        $mappingIds = $query->pluck('id')->toArray();

        foreach ($mappingIds as $mappingId) {
            $mapping = TimestampSongMapping::find($mappingId);
            if ($mapping) {
                $this->processMapping($mapping);
            }
        }
    }

    /**
     * 個別のマッピングを処理
     */
    private function processMapping(TimestampSongMapping $mapping): void
    {
        $originalNormalized = $mapping->normalized_text;
        $newNormalized = preg_replace($this->zeroWidthPattern, '', $mapping->normalized_text);
        $newNormalized = TextNormalizer::normalize($newNormalized);

        if ($originalNormalized === $newNormalized) {
            return;
        }

        // 同じ normalized_text のマッピングが既に存在するか確認（自分自身を除外）
        $existingMapping = TimestampSongMapping::where('normalized_text', $newNormalized)
            ->where('id', '!=', $mapping->id)
            ->first();

        if ($existingMapping) {
            $this->mergeMappings($mapping, $existingMapping, $originalNormalized, $newNormalized);
        } else {
            // 既存がない場合は更新
            $mapping->normalized_text = $newNormalized;
            $mapping->saveQuietly();

            Log::info('[Migration] Updated mapping', [
                'id' => $mapping->id,
                'original_normalized' => $originalNormalized,
                'new_normalized' => $newNormalized,
            ]);
        }
    }

    /**
     * 2つのマッピングを統合
     */
    private function mergeMappings(
        TimestampSongMapping $mapping,
        TimestampSongMapping $existingMapping,
        string $originalNormalized,
        string $newNormalized
    ): void {
        // 手動マッピングを優先して統合
        // 優先順位: 手動 > 自動、同じ場合は信頼度が高い方を優先
        $shouldUpdateExisting = false;

        if ($mapping->is_manual && ! $existingMapping->is_manual) {
            // 現在のマッピングが手動で、既存が自動の場合
            $shouldUpdateExisting = true;
        } elseif ($mapping->is_manual === $existingMapping->is_manual) {
            // 両方とも手動、または両方とも自動の場合は信頼度で判断
            if (($mapping->confidence ?? 0) > ($existingMapping->confidence ?? 0)) {
                $shouldUpdateExisting = true;
            }
        }
        // else: 既存が手動で現在が自動の場合は既存を保持

        if ($shouldUpdateExisting) {
            $existingMapping->update([
                'song_id' => $mapping->song_id,
                'is_manual' => $mapping->is_manual,
                'is_not_song' => $mapping->is_not_song,
                'confidence' => $mapping->confidence,
            ]);
        }

        // 古いマッピングを削除
        $mapping->delete();

        Log::info('[Migration] Merged mapping', [
            'deleted_id' => $mapping->id,
            'merged_into_id' => $existingMapping->id,
            'original_normalized' => $originalNormalized,
            'new_normalized' => $newNormalized,
            'updated_existing' => $shouldUpdateExisting,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // データ変更のため、ロールバックは対応しない
        Log::warning('[Migration] Rollback not supported for zero-width space fix');
    }
};
