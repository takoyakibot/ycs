<?php

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
        // SQLite対応: インデックスとカラムを段階的に操作
        Schema::table('change_list', function (Blueprint $table) {
            // 既存のインデックスを削除
            $table->dropIndex(['channel_id', 'type']);
        });

        Schema::table('change_list', function (Blueprint $table) {
            // 'type' カラムを削除
            $table->dropColumn('type');
        });

        Schema::table('change_list', function (Blueprint $table) {
            // SQLiteでは after() がサポートされていないため条件分岐
            if (DB::getDriverName() === 'sqlite') {
                $table->string('video_id', 26);
            } else {
                $table->string('video_id', 26)->after('channel_id');
            }
        });

        Schema::table('change_list', function (Blueprint $table) {
            // 新しいインデックスを追加
            $table->index(['channel_id', 'video_id', 'comment_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('change_list', function (Blueprint $table) {
            // 追加したインデックスを削除
            $table->dropIndex(['channel_id', 'video_id', 'comment_id']);

            // 'video_id' を削除
            $table->dropColumn('video_id');

            // 'type' を復元（enum: 1:archive, 2:comment, 3:description）
            $table->enum('type', ['1', '2', '3']);

            // 元のインデックスを復元
            $table->index(['channel_id', 'type']);
        });
    }
};
