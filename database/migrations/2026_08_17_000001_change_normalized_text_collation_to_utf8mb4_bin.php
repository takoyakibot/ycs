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
     * 2026_03_09_000001 で ts_items / timestamp_song_mappings 等を
     * utf8mb4 / utf8mb4_unicode_ci に変換した（timestamp_decompositions は対象外で、
     * テーブル作成時の接続既定 utf8mb4_unicode_ci が付いている）。
     * utf8mb4_unicode_ci は UCA 4.0.0 ベースで補助面（U+10000 以降＝ほとんどの絵文字）に
     * 重みを持たないため、MySQL 上では「🎵A」と「🎶A」が同値と判定される。
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
     * normalized_text を utf8mb4_bin（バイト比較）に変更し、
     * DB 側の比較をアプリ側の比較と一致させる。
     *
     * 【変換で影響を受ける範囲】
     * utf8mb4_unicode_ci が同値扱いしていたのは絵文字だけではない。
     * 半角/全角カナ（ｲｴｽﾀﾃﾞｲ = イエスタデイ）とアクセント記号（cafe = café）も同値で、
     * これらは TextNormalizer::normalize() が揃えないため（mb_convert_kana に 'as' しか
     * 渡しておらず 'K'/'H' を含まない）変換後は別物として扱われる。
     * 該当するタイムスタンプは未紐付に戻るので、logCollationConflicts() で記録する。
     *
     * 【注意】
     * - utf8mb4_bin は PAD SPACE であり NO PAD ではない。末尾スペースの有無は
     *   変換後も DB 側では無視される（normalize() が trim するため実害は無い想定）。
     *   末尾スペースまで厳密にしたい場合は utf8mb4_0900_bin が必要。
     * - utf8mb4_bin 列と utf8mb4_unicode_ci 列を JOIN してもエラーにはならない。
     *   同一 charset で片方が _bin の場合、MySQL は _bin を優先し、
     *   意味論が黙って変わる（部分適用状態が無警告で動いてしまう）。
     *   "Illegal mix of collations" が出るのは非バイナリ照合同士が食い違う場合のみ。
     *   3テーブルを同時に変換するのは、エラー回避のためではなく
     *   各テーブルの UNIQUE / WHERE の意味論を揃えるため。
     * - ALTER TABLE MODIFY はインデックスの再構築を伴うため、
     *   件数の多い ts_items ではメンテナンスウィンドウでの実施を推奨。
     * - 今後 normalized_text 系のカラムを追加する場合も utf8mb4_bin を指定すること。
     *   なお songs.normalized_title / normalized_artist は未対応（Issue で扱う）。
     */
    private const TARGET_COLUMNS = [
        // テーブル名 => [型, NULL可否]
        // MySQL は CHARACTER SET / COLLATE を型の直後に要求するため、
        // NULL 可否と分けて持つ（連結順を間違えると ERROR 1064 になる）
        'ts_items' => ['VARCHAR(255)', 'NULL'],
        'timestamp_song_mappings' => ['VARCHAR(255)', 'NOT NULL'],
        'timestamp_decompositions' => ['VARCHAR(255)', 'NOT NULL'],
    ];

    /**
     * MODIFY 文を組み立てる
     *
     * CHARACTER SET / COLLATE は型の直後、NULL 可否より前に置かなければならない。
     * 順序を誤ると ERROR 1064 になり、SQLite で動くテストでは検知できないため
     * 文字列生成だけを切り出してテストできるようにしている。
     */
    public static function buildModifyStatement(string $table, string $type, string $nullability): string
    {
        return "ALTER TABLE `{$table}` MODIFY `normalized_text` {$type}"
            .' CHARACTER SET utf8mb4 COLLATE utf8mb4_bin'
            ." {$nullability}";
    }

    /**
     * 変換対象のテーブル定義
     *
     * @return array<string, array{0: string, 1: string}>
     */
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

        foreach (self::TARGET_COLUMNS as $table => [$type, $nullability]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'normalized_text')) {
                continue;
            }

            // 既に utf8mb4_bin なら何もしない。
            // MODIFY は全行コピーとインデックス再構築を伴うため、
            // 再実行で ts_items のリビルドを二重に払わない
            if ($this->currentCollation($table) === 'utf8mb4_bin') {
                Log::info('[Migration] normalized_text is already utf8mb4_bin', ['table' => $table]);

                continue;
            }

            DB::statement(self::buildModifyStatement($table, $type, $nullability));
        }
    }

    /**
     * normalized_text の現在の照合順序を返す（取得できなければ null）
     */
    private function currentCollation(string $table): ?string
    {
        try {
            $row = DB::selectOne(
                'SELECT COLLATION_NAME AS collation_name
                   FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?
                    AND COLUMN_NAME = ?',
                [$table, 'normalized_text']
            );

            return $row->collation_name ?? null;
        } catch (\Throwable $e) {
            return null;
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
        // normalized_text で結合される組み合わせすべてを検査する。
        // ts_items × timestamp_song_mappings だけでは、分解済み
        // （timestamp_decompositions）の取りこぼしが記録に残らない
        $pairs = [
            ['ts_items', 'timestamp_song_mappings'],
            ['timestamp_decompositions', 'timestamp_song_mappings'],
            ['timestamp_decompositions', 'ts_items'],
        ];

        foreach ($pairs as [$left, $right]) {
            $this->logConflictsBetween($left, $right);
        }
    }

    private function logConflictsBetween(string $left, string $right): void
    {
        if (! Schema::hasTable($left) || ! Schema::hasTable($right)) {
            return;
        }

        try {
            $query = DB::table($left)
                ->join($right, "{$left}.normalized_text", '=', "{$right}.normalized_text")
                ->whereRaw(
                    "CONVERT({$left}.normalized_text USING binary) <> CONVERT({$right}.normalized_text USING binary)"
                )
                ->select(
                    "{$left}.normalized_text as left_normalized_text",
                    "{$right}.normalized_text as right_normalized_text"
                )
                ->distinct();

            // 件数は上限をかけずに数える。サンプルだけを絞る
            // （上限後の件数を記録すると、影響行が多いときに実数が分からない）
            $total = (clone $query)->count();

            if ($total === 0) {
                Log::info('[Migration] No collation conflicts found in normalized_text', [
                    'left' => $left,
                    'right' => $right,
                ]);

                return;
            }

            Log::warning('[Migration] normalized_text collation conflicts detected', [
                'left' => $left,
                'right' => $right,
                'count' => $total,
                'note' => '変換後これらは未紐付に戻るため、正規化画面での再紐付けが必要。'
                    .'絵文字だけでなく半角/全角カナ・アクセント記号の差も含む',
                'samples' => $query->limit(500)->get()->toArray(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Migration] Failed to detect normalized_text collation conflicts', [
                'left' => $left,
                'right' => $right,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * utf8mb4_unicode_ci に戻すと、絵文字だけが異なる normalized_text が
     * 同値と判定され UNIQUE 制約違反になり得るため、ロールバックできない。
     * 成功したふりをするとスキーマが bin のまま migrations テーブルから
     * レコードが消え、次の migrate で再度フルリビルドが走るので明示的に失敗させる。
     */
    public function down(): void
    {
        throw new RuntimeException(
            'normalized_text の照合順序変更はロールバックできません。'
            .'utf8mb4_unicode_ci に戻すと絵文字だけが異なる行が同値になり UNIQUE 制約違反になります。'
        );
    }
};
