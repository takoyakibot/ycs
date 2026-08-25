<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * songs.normalized_title / normalized_artist の照合順序を utf8mb4_bin に変更（#643）
     *
     * 2026_08_17 で ts_items / timestamp_song_mappings / timestamp_decompositions の
     * normalized_text を utf8mb4_bin に変更したが、songs の normalized_title /
     * normalized_artist は未対応のまま残っていた。
     *
     * utf8mb4_unicode_ci では絵文字・半角/全角カナ・アクセント記号が同値と判定されるため、
     * AutoLinkService・SongSearchService・SongMergeService 等の完全一致検索で
     * 誤マッチが起こり得る。
     *
     * 【変換で影響を受ける範囲】
     * SongMergeService::findDuplicates は normalized_title / normalized_artist で
     * GROUP BY した後、PHP の === で絞り込む「SQL曖昧比較 → PHP厳密比較」の構造を持つ。
     * 変換後は絵文字・半角全角カナ・アクセント記号だけが異なる行が別グループ扱いになり、
     * これまで重複候補として出ていた組が出なくなる（AutoLinkService の完全一致検索も同様）。
     * 該当する行は logCollationConflicts() で記録する。
     *
     * 【注意】
     * ALTER TABLE MODIFY は normalized_title / normalized_artist の両方に張られた
     * インデックスの再構築を伴うため、songs の件数が多い場合はメンテナンスウィンドウでの
     * 実施を推奨（2026_08_17 の ts_items と同様）。
     */
    private const TARGET_COLUMNS = [
        // カラム名 => [型, NULL可否]
        'normalized_title' => ['VARCHAR(255)', 'NULL'],
        'normalized_artist' => ['VARCHAR(255)', 'NULL'],
    ];

    public static function buildModifyStatement(string $column, string $type, string $nullability): string
    {
        return "ALTER TABLE `songs` MODIFY `{$column}` {$type}"
            .' CHARACTER SET utf8mb4 COLLATE utf8mb4_bin'
            ." {$nullability}";
    }

    public static function targetColumns(): array
    {
        return self::TARGET_COLUMNS;
    }

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $this->logCollationConflicts();

        foreach (self::TARGET_COLUMNS as $column => [$type, $nullability]) {
            if (! Schema::hasColumn('songs', $column)) {
                continue;
            }

            if ($this->currentCollation($column) === 'utf8mb4_bin') {
                Log::info('[Migration] songs.'.$column.' is already utf8mb4_bin');

                continue;
            }

            DB::statement(self::buildModifyStatement($column, $type, $nullability));
        }
    }

    private function logCollationConflicts(): void
    {
        foreach (array_keys(self::TARGET_COLUMNS) as $column) {
            if (! Schema::hasColumn('songs', $column)) {
                continue;
            }

            try {
                $total = DB::table('songs as a')
                    ->join('songs as b', "a.{$column}", '=', "b.{$column}")
                    ->whereRaw('a.id < b.id')
                    ->whereRaw(
                        "CONVERT(a.{$column} USING binary) <> CONVERT(b.{$column} USING binary)"
                    )
                    ->count();

                if ($total === 0) {
                    Log::info('[Migration] No collation conflicts found', ['column' => "songs.{$column}"]);

                    continue;
                }

                $samples = DB::table('songs as a')
                    ->join('songs as b', "a.{$column}", '=', "b.{$column}")
                    ->whereRaw('a.id < b.id')
                    ->whereRaw(
                        "CONVERT(a.{$column} USING binary) <> CONVERT(b.{$column} USING binary)"
                    )
                    ->select("a.{$column} as value_a", "b.{$column} as value_b", 'a.id as id_a', 'b.id as id_b')
                    ->limit(100)
                    ->get()
                    ->toArray();

                Log::warning('[Migration] songs collation conflicts detected', [
                    'column' => "songs.{$column}",
                    'count' => $total,
                    'note' => '変換後これらは別の値として扱われる',
                    'samples' => $samples,
                ]);
            } catch (\Throwable $e) {
                Log::warning('[Migration] Failed to detect collation conflicts', [
                    'column' => "songs.{$column}",
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    private function currentCollation(string $column): ?string
    {
        try {
            $row = DB::selectOne(
                'SELECT COLLATION_NAME AS collation_name
                   FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?
                    AND COLUMN_NAME = ?',
                ['songs', $column]
            );

            return $row->collation_name ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function down(): void
    {
        throw new RuntimeException(
            'songs の normalized_title / normalized_artist の照合順序変更はロールバックできません。'
            .'utf8mb4_unicode_ci に戻すと絵文字だけが異なる行が同値になり得ます。'
        );
    }
};
