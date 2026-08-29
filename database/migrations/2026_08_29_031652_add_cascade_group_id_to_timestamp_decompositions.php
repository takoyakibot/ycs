<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timestamp_decompositions', function (Blueprint $table) {
            $table->string('cascade_group_id', 26)->nullable()->after('confidence')->index();
        });
    }

    public function down(): void
    {
        Schema::table('timestamp_decompositions', function (Blueprint $table) {
            $table->dropColumn('cascade_group_id');
        });
    }
};
