<?php

use App\Helpers\TextNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // タグもあいまい検索（normalized_title / normalized_artist と同じ正規化）の対象にするためのカラム
        Schema::table('song_tags', function (Blueprint $table) {
            $table->string('normalized_value')->nullable()->after('value');
            $table->index('normalized_value');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE `song_tags` MODIFY `normalized_value` VARCHAR(255)'
                .' CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL'
            );
        }

        DB::table('song_tags')->orderBy('id')->chunkById(500, function ($tags) {
            foreach ($tags as $tag) {
                DB::table('song_tags')->where('id', $tag->id)
                    ->update(['normalized_value' => TextNormalizer::normalize($tag->value)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('song_tags', function (Blueprint $table) {
            $table->dropIndex(['normalized_value']);
            $table->dropColumn('normalized_value');
        });
    }
};
