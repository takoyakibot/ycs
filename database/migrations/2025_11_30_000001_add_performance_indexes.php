<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // archives テーブルのインデックス追加
        Schema::table('archives', function (Blueprint $table) {
            // アーカイブ一覧取得で頻用、channel_idとis_displayの複合インデックス
            $table->index(['channel_id', 'is_display'], 'archives_channel_is_display_idx');
            // orderBy('published_at', 'desc') が頻出
            $table->index('published_at', 'archives_published_at_idx');
        });

        // ts_items テーブルのインデックス追加
        Schema::table('ts_items', function (Blueprint $table) {
            // WHERE is_display=1 が多用、テーブルスキャン回避
            $table->index('is_display', 'ts_items_is_display_idx');
            // change_list との JOIN で必須
            $table->index(['video_id', 'comment_id', 'is_display'], 'ts_items_video_comment_display_idx');
        });

        // channels テーブルのインデックス追加
        Schema::table('channels', function (Blueprint $table) {
            // Auth::user()->channels() で毎回フィルター
            $table->index('user_id', 'channels_user_id_idx');
        });

        // timestamp_song_mappings テーブルのインデックス追加
        Schema::table('timestamp_song_mappings', function (Blueprint $table) {
            // song削除時の cascading delete で必要
            $table->index(['song_id', 'created_at'], 'tsm_song_created_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('archives', function (Blueprint $table) {
            $table->dropIndex('archives_channel_is_display_idx');
            $table->dropIndex('archives_published_at_idx');
        });

        Schema::table('ts_items', function (Blueprint $table) {
            $table->dropIndex('ts_items_is_display_idx');
            $table->dropIndex('ts_items_video_comment_display_idx');
        });

        Schema::table('channels', function (Blueprint $table) {
            $table->dropIndex('channels_user_id_idx');
        });

        Schema::table('timestamp_song_mappings', function (Blueprint $table) {
            $table->dropIndex('tsm_song_created_idx');
        });
    }
};
