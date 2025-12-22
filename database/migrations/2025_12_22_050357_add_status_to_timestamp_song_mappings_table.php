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
        Schema::table('timestamp_song_mappings', function (Blueprint $table) {
            // status: linked（紐付け済み）, pending（保留）
            // 既存データはlinkedとして扱う
            $table->string('status', 20)->default('linked')->after('is_not_song')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('timestamp_song_mappings', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn('status');
        });
    }
};
