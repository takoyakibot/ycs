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
        Schema::table('channels', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->after('handle');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
        });

        // 既存のチャンネルをuser_id=2に紐づけ
        DB::table('channels')->whereNull('user_id')->update(['user_id' => 2]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
