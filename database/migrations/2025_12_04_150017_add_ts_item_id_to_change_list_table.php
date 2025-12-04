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
        // ts_item_idカラムを追加
        Schema::table('change_list', function (Blueprint $table) {
            if (DB::getDriverName() === 'sqlite') {
                $table->string('ts_item_id', 26)->nullable();
            } else {
                $table->string('ts_item_id', 26)->nullable()->after('comment_id');
            }
        });

        // タイムスタンプ単位の検索用インデックスを追加
        Schema::table('change_list', function (Blueprint $table) {
            $table->index(['video_id', 'ts_item_id'], 'change_list_video_id_ts_item_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('change_list', function (Blueprint $table) {
            $table->dropIndex('change_list_video_id_ts_item_id_index');
        });

        Schema::table('change_list', function (Blueprint $table) {
            $table->dropColumn('ts_item_id');
        });
    }
};
