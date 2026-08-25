<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * コメントからのタイムスタンプ取得を試行した日時。
     * コメントが0件だった動画を次回リフレッシュで再試行しないために使う（#654）
     */
    public function up(): void
    {
        Schema::table('archives', function (Blueprint $table) {
            $table->timestamp('comments_fetched_at')->nullable()->after('subtitles_unavailable_at');
        });
    }

    public function down(): void
    {
        Schema::table('archives', function (Blueprint $table) {
            $table->dropColumn('comments_fetched_at');
        });
    }
};
