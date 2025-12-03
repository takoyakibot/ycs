<?php

use App\Helpers\TextNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            $table->string('normalized_title')->after('title')->nullable();
            $table->string('normalized_artist')->after('artist')->nullable();

            $table->index('normalized_title');
            $table->index('normalized_artist');
        });

        // 既存データに正規化済みの値を設定
        DB::table('songs')->orderBy('id')->chunk(100, function ($songs) {
            foreach ($songs as $song) {
                DB::table('songs')
                    ->where('id', $song->id)
                    ->update([
                        'normalized_title' => TextNormalizer::normalize($song->title),
                        'normalized_artist' => TextNormalizer::normalize($song->artist),
                    ]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            $table->dropIndex(['normalized_title']);
            $table->dropIndex(['normalized_artist']);
            $table->dropColumn(['normalized_title', 'normalized_artist']);
        });
    }
};
