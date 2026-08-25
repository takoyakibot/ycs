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
