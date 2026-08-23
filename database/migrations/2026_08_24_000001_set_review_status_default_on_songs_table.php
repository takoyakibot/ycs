<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('songs')->whereNull('review_status')->update([
            'review_status' => 'needs_review',
        ]);

        Schema::table('songs', function (Blueprint $table) {
            $table->string('review_status', 20)->default('needs_review')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            $table->string('review_status', 20)->default(null)->nullable()->change();
        });
    }
};
