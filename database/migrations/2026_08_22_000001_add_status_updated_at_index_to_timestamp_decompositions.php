<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timestamp_decompositions', function (Blueprint $table) {
            $table->index(['status', 'updated_at', 'id'], 'ts_decomp_status_updated_at_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('timestamp_decompositions', function (Blueprint $table) {
            $table->dropIndex('ts_decomp_status_updated_at_id_idx');
        });
    }
};
