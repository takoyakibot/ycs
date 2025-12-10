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
            // タイムスタンプのマッチング用カラム
            // アーカイブ更新時にts_item_idが変わっても、これらの値で照合できる
            $table->string('ts_text', 20)->nullable()->after('ts_item_id');
            $table->integer('ts_num')->nullable()->after('ts_text');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('change_list', function (Blueprint $table) {
            $table->dropColumn(['ts_text', 'ts_num']);
        });
    }
};
