<?php

namespace Tests\Unit\Migrations;

use Tests\TestCase;

/**
 * normalized_text の照合順序変更マイグレーションが生成するSQLを検証する
 *
 * テストは SQLite で動くため、up() は冒頭のドライバ判定で return して
 * 何も実行されない。つまり生成されるSQLの正しさは、実行では一切検証できない。
 *
 * MySQL は CHARACTER SET / COLLATE を型の直後（NULL 可否より前）に要求するので、
 * 連結順を誤ると ERROR 1064 になりデプロイが止まる。文字列生成だけを
 * 切り出して固定することで、その退行をSQLite上でも検知できるようにする。
 */
class ChangeNormalizedTextCollationTest extends TestCase
{
    private function migration(): object
    {
        return require database_path(
            'migrations/2026_08_17_000001_change_normalized_text_collation_to_utf8mb4_bin.php'
        );
    }

    /**
     * CHARACTER SET / COLLATE が型の直後、NULL 可否より前に来ること
     */
    public function test_modify_statement_places_collation_before_nullability(): void
    {
        $migration = $this->migration();

        $this->assertSame(
            'ALTER TABLE `ts_items` MODIFY `normalized_text` VARCHAR(255)'
            .' CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL',
            $migration::buildModifyStatement('ts_items', 'VARCHAR(255)', 'NULL')
        );

        $this->assertSame(
            'ALTER TABLE `timestamp_song_mappings` MODIFY `normalized_text` VARCHAR(255)'
            .' CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL',
            $migration::buildModifyStatement('timestamp_song_mappings', 'VARCHAR(255)', 'NOT NULL')
        );
    }

    /**
     * NULL 可否が COLLATE より前に来ていないこと
     *
     * この順序が MySQL で ERROR 1064 になる形。文字列の一致だけだと
     * 意図が読み取りにくいので、順序そのものを主張しておく。
     */
    public function test_nullability_never_precedes_collation(): void
    {
        $migration = $this->migration();

        foreach ($migration::targetColumns() as $table => [$type, $nullability]) {
            $sql = $migration::buildModifyStatement($table, $type, $nullability);

            $collatePos = strpos($sql, 'COLLATE');
            $nullPos = strrpos($sql, $nullability);

            $this->assertNotFalse($collatePos, "COLLATE が含まれていない: {$table}");
            $this->assertNotFalse($nullPos, "NULL 可否が含まれていない: {$table}");
            $this->assertLessThan(
                $nullPos,
                $collatePos,
                "COLLATE は NULL 可否より前に置く必要がある: {$table}"
            );
        }
    }

    /**
     * 変換対象が3テーブルであること
     *
     * normalized_text を持つテーブルは ts_items / timestamp_song_mappings /
     * timestamp_decompositions の3つ。1つでも漏れると、そのテーブルだけ
     * 照合順序が揃わず UNIQUE / WHERE の意味論がずれる。
     */
    public function test_targets_all_tables_having_normalized_text(): void
    {
        $migration = $this->migration();

        $this->assertSame(
            ['ts_items', 'timestamp_song_mappings', 'timestamp_decompositions'],
            array_keys($migration::targetColumns())
        );
    }

    /**
     * ロールバックが明示的に失敗すること
     *
     * 成功したふりをするとスキーマは bin のまま migrations テーブルから
     * レコードが消え、次の migrate で再度フルリビルドが走る。
     */
    public function test_down_throws(): void
    {
        $migration = $this->migration();

        $this->expectException(\RuntimeException::class);

        $migration->down();
    }
}
