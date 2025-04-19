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
        // channel_id,video_id,comment_idのインデックスを削除
        Schema::table('change_list', function (Blueprint $table) {
            $table->dropIndex(['channel_id', 'video_id', 'comment_id']);
        });
        // channel_idを削除
        Schema::table('change_list', function (Blueprint $table) {
            $table->dropColumn('channel_id');
        });
        // video_idの桁数を11に変更
        Schema::table('change_list', function (Blueprint $table) {
            $table->string('video_id', 11)->change();
        });
        // comment_idの桁数を26、nullableに変更
        Schema::table('change_list', function (Blueprint $table) {
            $table->string('comment_id', 26)->nullable()->after('video_id')->change();
        });
        // video_id,comment_idのインデックスを追加
        Schema::table('change_list', function (Blueprint $table) {
            $table->index(['video_id', 'comment_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // channel_idを復元
        Schema::table('change_list', function (Blueprint $table) {
            $table->string('channel_id', 26)->after('id');
        });
        // channel_id,video_id,comment_idのインデックスを追加
        Schema::table('change_list', function (Blueprint $table) {
            $table->index(['channel_id', 'video_id', 'comment_id']);
        });
        // video_id,comment_idのインデックスを削除
        Schema::table('change_list', function (Blueprint $table) {
            $table->dropIndex(['video_id', 'comment_id']);
        });
    }
};
