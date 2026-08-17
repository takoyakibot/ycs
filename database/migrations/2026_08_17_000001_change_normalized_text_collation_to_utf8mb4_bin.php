<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * normalized_text の照合順序を utf8mb4_bin に変更
     *
     * 【背景】
     * 2026_03_09_000001 で各テーブルを utf8mb4 / utf8mb4_unicode_ci に変換した。
     * utf8mb4_unicode_ci は UCA 4.0.0 ベースで補助面（U+10000 以降＝ほとんどの絵文字）に
     * 重みを持たないため、MySQL 上では「🎵A」と「🎶A」が同値と判定される。
     * さらに PAD SPACE 照合のため末尾スペースの有無も無視される。
     *
     * 【不具合】
     * アプリ側は normalized_text をバイト完全一致で扱う（PHP の === / keyBy / get）のに対し、
     * DB 側は上記のとおり曖昧一致になるため、両者の判定結果がずれる。
     * 例: 絵文字で装飾されたタイムスタンプを正規化画面で紐付けると、
     *   1. SongMappingService::linkTimestamp の where('normalized_text', ...) が
     *      別の絵文字を持つ既存マッピング行を拾い、その行を更新してしまう
     *      （対象テキストのマッピング行は作られない）
     *   2. 一覧表示は PHP 側の keyBy/get でマッピングを引き当てるため該当なしとなる
     * 結果として「紐付けたはずなのに未紐付のまま」になる。
     *
     * 【対応】
     * normalized_text を utf8mb4_bin（NO PAD・バイト比較）に変更し、
     * DB 側の比較をアプリ側の比較と一致させる。
     * normalized_text は TextNormalizer::normalize() で小文字化済みのため、
     * バイト比較にしても大文字小文字の取りこぼしは発生しない。
     *
     * 【注意】
     * - normalized_text で JOIN / whereColumn するテーブルは照合順序を揃える必要がある
     *   （揃っていないと "Illegal mix of collations" エラーになる）ため、
     *   ts_items / timestamp_song_mappings / timestamp_decompositions を同時に変換する。
     * - ALTER TABLE MODIFY はインデックスの再構築を伴うため、
     *   件数の多い ts_items ではメンテナンスウィンドウでの実施を推奨。
     * - 今後 normalized_text 系のカラムを追加する場合も utf8mb4_bin を指定すること。
     */
    private const TARGET_COLUMNS = [
        // テーブル名 => MODIFY 用のカラム定義（NULL 可否まで含めて元定義と一致させること）
        'ts_items' => 'VARCHAR(255) NULL',
        'timestamp_song_mappings' => 'VARCHAR(255) NOT NULL',
        'timestamp_decompositions' => 'VARCHAR(255) NOT NULL',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $this->logCollationConflicts();

        foreach (self::TARGET_COLUMNS as $table => $definition) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'normalized_text')) {
                continue;
            }

            DB::statement(
                "ALTER TABLE `{$table}` MODIFY `normalized_text` {$definition} CHARACTER SET utf8mb4 COLLATE utf8mb4_bin"
            );
        }
    }

    /**
     * 照合順序の違いで誤マッチしていたタイムスタンプを記録する
     *
     * 変換後は該当タイムスタンプが「未紐付」に戻るため、
     * どのタイムスタンプが影響を受けたかを追跡できるようログに残す。
     * 診断目的のため、失敗しても変換自体は継続する。
     */
    private function logCollationConflicts(): void
    {
        if (! Schema::hasTable('ts_items') || ! Schema::hasTable('timestamp_song_mappings')) {
            return;
        }

        try {
            $conflicts = DB::table('ts_items')
                ->join('timestamp_song_mappings', 'ts_items.normalized_text', '=', 'timestamp_song_mappings.normalized_text')
                ->whereRaw('CONVERT(ts_items.normalized_text USING binary) <> CONVERT(timestamp_song_mappings.normalized_text USING binary)')
                ->select(
                    'ts_items.normalized_text as item_normalized_text',
                    'timestamp_song_mappings.normalized_text as mapping_normalized_text',
                    'timestamp_song_mappings.id as mapping_id',
                    'timestamp_song_mappings.song_id as song_id'
                )
                ->distinct()
                ->limit(500)
                ->get();

            if ($conflicts->isEmpty()) {
                Log::info('[Migration] No collation conflicts found in normalized_text');

                return;
            }

            Log::warning('[Migration] normalized_text collation conflicts detected', [
                'count' => $conflicts->count(),
                'note' => '変換後これらのタイムスタンプは未紐付に戻るため、正規化画面での再紐付けが必要',
                'conflicts' => $conflicts->toArray(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Migration] Failed to detect normalized_text collation conflicts', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * utf8mb4_unicode_ci に戻すと、絵文字だけが異なる normalized_text が
     * 同値と判定され UNIQUE 制約違反になり得るため、ロールバックは行わない。
     */
    public function down(): void
    {
        Log::warning('[Migration] Rollback not supported for normalized_text collation change');
    }
};
