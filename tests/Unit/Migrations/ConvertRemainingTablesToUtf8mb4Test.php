<?php

namespace Tests\Unit\Migrations;

use Tests\TestCase;

/**
 * utf8mb4 変換漏れテーブルの変換マイグレーション（#642）が生成するSQLを検証する
 *
 * テストは SQLite で動くため、up() は冒頭のドライバ判定で return して
 * 何も実行されない。生成されるSQLの正しさは実行では検証できないので、
 * 文字列生成を切り出して固定する（ChangeNormalizedTextCollationTest と同じ方式）。
 */
class ConvertRemainingTablesToUtf8mb4Test extends TestCase
{
    private function migration(): object
    {
        return require database_path(
            'migrations/2026_08_20_000001_convert_remaining_tables_to_utf8mb4.php'
        );
    }

    /**
     * CONVERT TO の対象テーブルが棚卸しの結果と一致すること
     *
     * timestamp_decompositions は normalized_text が utf8mb4_bin（UNIQUE付き）のため
     * CONVERT TO の対象にしてはならない（_unicode_ci に上書きされ、絵文字違いの行が
     * UNIQUE 衝突する）。ここに追加されたら設計が壊れている。
     */
    public function test_convert_tables_do_not_include_tables_with_bin_columns(): void
    {
        $migration = $this->migration();

        $this->assertSame(
            ['users', 'channel_excluded_words', 'channel_strip_patterns', 'subtitle_fingerprints'],
            $migration::convertTables()
        );

        $this->assertNotContains('timestamp_decompositions', $migration::convertTables());
        $this->assertNotContains('ts_items', $migration::convertTables());
        $this->assertNotContains('timestamp_song_mappings', $migration::convertTables());
    }

    public function test_convert_statement_format(): void
    {
        $migration = $this->migration();

        $this->assertSame(
            'ALTER TABLE `users` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $migration::buildConvertStatement('users')
        );
    }

    /**
     * timestamp_decompositions は normalized_text に触れないこと
     */
    public function test_decomposition_statements_do_not_touch_normalized_text(): void
    {
        $migration = $this->migration();

        foreach ($migration::decompositionStatements() as $sql) {
            $this->assertStringNotContainsString('normalized_text', $sql);
            $this->assertStringNotContainsString('CONVERT TO', $sql);
        }
    }

    /**
     * MODIFY 文で CHARACTER SET / COLLATE が NULL 可否より前に来ること
     *
     * 順序を誤ると MySQL で ERROR 1064 になる（SQLite のテストでは検知できない）。
     */
    public function test_decomposition_modify_places_collation_before_nullability(): void
    {
        $migration = $this->migration();

        $statements = $migration::decompositionStatements();

        $this->assertSame(
            'ALTER TABLE `timestamp_decompositions`'
            .' DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $statements[0]
        );

        $this->assertSame(
            'ALTER TABLE `timestamp_decompositions`'
            .' MODIFY `original_text` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,'
            .' MODIFY `derived_title` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,'
            .' MODIFY `derived_artist` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL',
            $statements[1]
        );
    }
}
