<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            $table->string('title_comparison_key')->nullable()->after('normalized_artist');
            $table->string('artist_comparison_key')->nullable()->after('title_comparison_key');
            $table->index('title_comparison_key');
            $table->index('artist_comparison_key');
        });

        Schema::table('ts_items', function (Blueprint $table) {
            $table->string('comparison_key')->nullable()->after('normalized_text');
            $table->index('comparison_key');
        });

        Schema::table('timestamp_song_mappings', function (Blueprint $table) {
            $table->string('comparison_key')->nullable()->after('normalized_text');
            $table->index('comparison_key');
        });

        if (DB::getDriverName() === 'mysql') {
            foreach (self::targetColumns() as [$table, $column]) {
                DB::statement(
                    "ALTER TABLE `{$table}` MODIFY `{$column}` VARCHAR(255)"
                    .' CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL'
                );
            }
        }

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            $table->dropIndex(['title_comparison_key']);
            $table->dropIndex(['artist_comparison_key']);
            $table->dropColumn(['title_comparison_key', 'artist_comparison_key']);
        });

        Schema::table('ts_items', function (Blueprint $table) {
            $table->dropIndex(['comparison_key']);
            $table->dropColumn('comparison_key');
        });

        Schema::table('timestamp_song_mappings', function (Blueprint $table) {
            $table->dropIndex(['comparison_key']);
            $table->dropColumn('comparison_key');
        });
    }

    public static function targetColumns(): array
    {
        return [
            ['songs', 'title_comparison_key'],
            ['songs', 'artist_comparison_key'],
            ['ts_items', 'comparison_key'],
            ['timestamp_song_mappings', 'comparison_key'],
        ];
    }

    private function backfill(): void
    {
        $normalizer = \App\Helpers\TextNormalizer::class;

        // songs
        \App\Models\Song::query()
            ->whereNotNull('normalized_title')
            ->chunkById(500, function ($songs) use ($normalizer) {
                foreach ($songs as $song) {
                    $titleKey = $normalizer::toComparisonKey($song->normalized_title);
                    $artistKey = $normalizer::toComparisonKey($song->normalized_artist);
                    DB::table('songs')->where('id', $song->id)->update([
                        'title_comparison_key' => $titleKey ?: null,
                        'artist_comparison_key' => $artistKey ?: null,
                    ]);
                }
            });

        // ts_items
        DB::table('ts_items')
            ->whereNotNull('normalized_text')
            ->orderBy('id')
            ->chunkById(1000, function ($items) use ($normalizer) {
                foreach ($items as $item) {
                    $key = $normalizer::toComparisonKey($item->normalized_text);
                    DB::table('ts_items')->where('id', $item->id)->update([
                        'comparison_key' => $key ?: null,
                    ]);
                }
            });

        // timestamp_song_mappings
        DB::table('timestamp_song_mappings')
            ->whereNotNull('normalized_text')
            ->orderBy('id')
            ->chunkById(1000, function ($items) use ($normalizer) {
                foreach ($items as $item) {
                    $key = $normalizer::toComparisonKey($item->normalized_text);
                    DB::table('timestamp_song_mappings')->where('id', $item->id)->update([
                        'comparison_key' => $key ?: null,
                    ]);
                }
            });
    }
};
