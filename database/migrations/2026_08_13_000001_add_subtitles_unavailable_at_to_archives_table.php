<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 字幕が存在しないと確認できた日時。
     * 字幕一括取得スキャンの対象から除外するために使い、
     * 字幕が保存された時点でクリアされる（#603）
     */
    public function up(): void
    {
        Schema::table('archives', function (Blueprint $table) {
            $table->timestamp('subtitles_unavailable_at')->nullable()->after('comments_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('archives', function (Blueprint $table) {
            $table->dropColumn('subtitles_unavailable_at');
        });
    }
};
