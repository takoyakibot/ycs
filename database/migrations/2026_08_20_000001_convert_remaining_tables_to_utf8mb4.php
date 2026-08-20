<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * utf8mb4 変換漏れテーブルの変換（#642）
     *
     * 2026_03_09_000001 のテーブル一覧に入っていなかった、ユーザー入力由来の
     * テキストを持つテーブルを utf8mb4 に揃える。テーブルが utf8（3バイト）のままだと
     * 絵文字（4バイト UTF-8）が保存できない（strict モードならエラー、非 strict なら切り捨て）。
     *
     * 【対象と方式】
     * - users / channel_excluded_words / channel_strip_patterns / subtitle_fingerprints:
     *   CONVERT TO で一括変換。text 系カラムに utf8mb4_bin など個別の照合順序を
     *   持つカラムが無く、UNIQUE 索引（email / channel_id+word / channel_id+pattern）は
     *   utf8_unicode_ci → utf8mb4_unicode_ci で同値関係が変わらない（utf8 には
     *   絵文字が保存できなかった＝補助面の衝突が新たに生じない）ため安全。
     * - timestamp_decompositions: normalized_text が 2026_08_17_000001 で
     *   utf8mb4_bin（UNIQUE付き）になっているため、CONVERT TO は使えない
     *   （全カラムの照合順序を上書きし、_unicode_ci に戻すと絵文字違いの行が
     *   UNIQUE 衝突で errno 1062 になり得る）。ユーザー入力由来の
     *   original_text / derived_title / derived_artist のみ MODIFY で変換し、
     *   テーブルの既定 charset も utf8mb4 に変更する（既定変更はメタデータのみ）。
     *
     * 【対象外とした理由】
     * - normalization_logs / video_subtitles: ユーザー入力由来のテキストは JSON カラム
     *   （details / subtitle_data）にあり、MySQL の JSON 型は常に utf8mb4 で扱われる。
     *   他のカラムは ASCII のみ（ID・種別名）。
     * - timestamp_decompositions の id / song_id / created_by / updated_by / status:
     *   ULID・enum で ASCII のみ。変換しても意味は変わらないが、本 Issue の範囲
     *   （絵文字の保存可否）に絞る。
     *
     * 【冪等性】
     * 実カラムの charset を information_schema で確認し、utf8mb4 でないテーブルのみ
     * 変換する。接続既定が utf8mb4 になってから作られた環境（新規MySQL環境・CI）では
     * 全テーブルが utf8mb4 で作成されるため、何もしない。
     *
     * 【注意】
     * CONVERT TO はテーブル全体のコピーとメタデータロックを伴う。対象テーブルは
     * いずれも小規模の想定だが、本番適用時は 2026_03_09_000001 と同様
     * メンテナンスウィンドウでの実施を推奨。
     */
    private const CONVERT_TABLES = [
        'users',
        'channel_excluded_words',
        'channel_strip_patterns',
        'subtitle_fingerprints',
    ];

    private const DECOMPOSITION_TEXT_COLUMNS = [
        'original_text',
        'derived_title',
        'derived_artist',
    ];

    /**
     * @return list<string>
     */
    public static function convertTables(): array
    {
        return self::CONVERT_TABLES;
    }

    public static function buildConvertStatement(string $table): string
    {
        return "ALTER TABLE `{$table}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
    }

    /**
     * timestamp_decompositions 用の変換SQL
     *
     * CHARACTER SET / COLLATE は型の直後、NULL 可否より前に置くこと
     * （2026_08_17_000001 と同じ制約。順序を誤ると ERROR 1064 になり、
     * SQLite で動くテストでは実行では検知できない）。
     *
     * @return list<string>
     */
    public static function decompositionStatements(): array
    {
        return [
            'ALTER TABLE `timestamp_decompositions`'
                .' DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            'ALTER TABLE `timestamp_decompositions`'
                .' MODIFY `original_text` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,'
                .' MODIFY `derived_title` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,'
                .' MODIFY `derived_artist` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL',
        ];
    }

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::CONVERT_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (! $this->hasNonUtf8mb4Column($table)) {
                Log::info('[Migration] Table is already utf8mb4', ['table' => $table]);

                continue;
            }

            DB::statement(self::buildConvertStatement($table));
        }

        if (Schema::hasTable('timestamp_decompositions')
            && $this->hasNonUtf8mb4Column('timestamp_decompositions', self::DECOMPOSITION_TEXT_COLUMNS)) {
            foreach (self::decompositionStatements() as $statement) {
                DB::statement($statement);
            }
        } else {
            Log::info('[Migration] timestamp_decompositions text columns are already utf8mb4');
        }
    }

    /**
     * utf8mb4 でない文字列カラムが残っているか
     *
     * @param  list<string>|null  $columns  検査対象を限定する場合のカラム名（null なら全カラム）
     */
    private function hasNonUtf8mb4Column(string $table, ?array $columns = null): bool
    {
        $sql = 'SELECT COUNT(*) AS cnt
                  FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND CHARACTER_SET_NAME IS NOT NULL
                   AND CHARACTER_SET_NAME <> ?';
        $bindings = [$table, 'utf8mb4'];

        if ($columns !== null) {
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $sql .= " AND COLUMN_NAME IN ({$placeholders})";
            $bindings = array_merge($bindings, $columns);
        }

        $row = DB::selectOne($sql, $bindings);

        return ($row->cnt ?? 0) > 0;
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // charsetの変換は元に戻さない（2026_03_09_000001 と同じ方針。
        // utf8 に戻すと絵文字を含む既存データが失われる可能性があるため）
    }
};
