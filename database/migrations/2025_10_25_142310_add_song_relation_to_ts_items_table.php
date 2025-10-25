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
            $table->unsignedBigInteger('song_id')->nullable(); // 楽曲マスタとの紐づけ
            $table->boolean('is_not_song')->default(false); // 楽曲ではないかどうかのフラグ
            $table->foreign('song_id')->references('id')->on('songs')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ts_items', function (Blueprint $table) {
            $table->dropForeign(['song_id']);
            $table->dropColumn(['song_id', 'is_not_song']);
        });
    }
};
