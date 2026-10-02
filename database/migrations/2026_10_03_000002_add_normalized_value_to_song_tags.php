<?php

use App\Helpers\TextNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public const MODIFY_STATEMENT = 'ALTER TABLE `song_tags` MODIFY `normalized_value` VARCHAR(255)'
        .' CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL';

    public function up(): void
    {
        // タグもあいまい検索（normalized_title / normalized_artist と同じ正規化）の対象にするためのカラム。
        // 検索は前方不定の LIKE なのでインデックスは張らない。
        // MySQL の DDL は暗黙コミットされ、バックフィル途中で失敗すると列だけ残るため、再実行できるようにしておく
        if (! Schema::hasColumn('song_tags', 'normalized_value')) {
            Schema::table('song_tags', function (Blueprint $table) {
                $table->string('normalized_value')->nullable()->after('value');
            });
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement(self::MODIFY_STATEMENT);
        }

        DB::table('song_tags')->whereNull('normalized_value')->orderBy('id')->chunkById(500, function ($tags) {
            foreach ($tags as $tag) {
                DB::table('song_tags')->where('id', $tag->id)
                    ->update(['normalized_value' => TextNormalizer::normalize($tag->value)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('song_tags', function (Blueprint $table) {
            $table->dropColumn('normalized_value');
        });
    }
};
