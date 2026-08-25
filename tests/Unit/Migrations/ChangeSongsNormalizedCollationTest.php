<?php

namespace Tests\Unit\Migrations;

use Tests\TestCase;

class ChangeSongsNormalizedCollationTest extends TestCase
{
    private function migration(): object
    {
        return require database_path(
            'migrations/2026_08_26_000001_change_songs_normalized_collation_to_utf8mb4_bin.php'
        );
    }

    public function test_modify_statement_places_collation_before_nullability(): void
    {
        $migration = $this->migration();

        $this->assertSame(
            'ALTER TABLE `songs` MODIFY `normalized_title` VARCHAR(255)'
            .' CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL',
            $migration::buildModifyStatement('normalized_title', 'VARCHAR(255)', 'NULL')
        );

        $this->assertSame(
            'ALTER TABLE `songs` MODIFY `normalized_artist` VARCHAR(255)'
            .' CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL',
            $migration::buildModifyStatement('normalized_artist', 'VARCHAR(255)', 'NULL')
        );
    }

    public function test_nullability_never_precedes_collation(): void
    {
        $migration = $this->migration();

        foreach ($migration::targetColumns() as $column => [$type, $nullability]) {
            $sql = $migration::buildModifyStatement($column, $type, $nullability);

            $collatePos = strpos($sql, 'COLLATE');
            $nullPos = strrpos($sql, $nullability);

            $this->assertNotFalse($collatePos, "COLLATE が含まれていない: {$column}");
            $this->assertNotFalse($nullPos, "NULL 可否が含まれていない: {$column}");
            $this->assertLessThan(
                $nullPos,
                $collatePos,
                "COLLATE は NULL 可否より前に置く必要がある: {$column}"
            );
        }
    }

    public function test_targets_both_normalized_columns(): void
    {
        $migration = $this->migration();

        $this->assertSame(
            ['normalized_title', 'normalized_artist'],
            array_keys($migration::targetColumns())
        );
    }

    public function test_down_throws(): void
    {
        $migration = $this->migration();

        $this->expectException(\RuntimeException::class);

        $migration->down();
    }
}
