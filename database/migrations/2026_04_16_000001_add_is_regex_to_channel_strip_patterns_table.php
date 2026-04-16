<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_strip_patterns', function (Blueprint $table) {
            $table->boolean('is_regex')->default(false)->after('pattern');
        });
    }

    public function down(): void
    {
        Schema::table('channel_strip_patterns', function (Blueprint $table) {
            $table->dropColumn('is_regex');
        });
    }
};
