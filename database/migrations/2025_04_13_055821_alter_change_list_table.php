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
        Schema::table('change_list', function (Blueprint $table) {
            // 'type' カラムを削除
            $table->dropColumn('type');

            // 'video_id' を追加（string, 26桁, not null）
            $table->string('video_id', 26);

            // インデックスを追加
            $table->index(['channel_id', 'video_id', 'comment_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('change_list', function (Blueprint $table) {
            // 追加した 'video_id' とインデックスを削除
            $table->dropIndex(['channel_id', 'video_id', 'comment_id']);
            $table->dropColumn('video_id');

            // 'type' を復元（enum: 1:archive, 2:comment, 3:description）
            $table->enum('type', ['1', '2', '3']);
        });
    }
};
