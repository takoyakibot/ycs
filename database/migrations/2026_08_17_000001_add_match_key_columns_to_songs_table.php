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
     *
     * 照合専用キーを保持するカラムを追加する。
     * normalized_title/normalized_artist は表記を統一した値だが、
     * タイムスタンプ側には記号や曲番号などの装飾が付くため完全一致では照合できない。
     * 記号類を除去したキーを持たせ、包含判定で照合できるようにする。
     */
    public function up(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            $table->string('match_key_title')->after('normalized_title')->nullable();
            $table->string('match_key_artist')->after('normalized_artist')->nullable();

            $table->index('match_key_title');
            $table->index('match_key_artist');
        });

        // 既存データに照合キーを設定
        DB::table('songs')->orderBy('id')->chunk(200, function ($songs) {
            foreach ($songs as $song) {
                DB::table('songs')
                    ->where('id', $song->id)
                    ->update([
                        'match_key_title' => TextNormalizer::matchKey($song->title),
                        'match_key_artist' => TextNormalizer::matchKey($song->artist),
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
            $table->dropIndex(['match_key_title']);
            $table->dropIndex(['match_key_artist']);
            $table->dropColumn(['match_key_title', 'match_key_artist']);
        });
    }
};
