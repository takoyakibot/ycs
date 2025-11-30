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
        Schema::table('ts_items', function (Blueprint $table) {
            $table->string('normalized_text')->nullable()->after('text');
            $table->index('normalized_text', 'ts_items_normalized_text_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ts_items', function (Blueprint $table) {
            $table->dropIndex('ts_items_normalized_text_idx');
            $table->dropColumn('normalized_text');
        });
    }
};
