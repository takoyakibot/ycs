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
            $table->index('normalized_text');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('timestamp_song_mappings', function (Blueprint $table) {
            $table->dropIndex(['normalized_text']);
        });
    }
};
