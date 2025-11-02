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
        Schema::create('songs', function (Blueprint $table) {
            $table->string('id', 26)->primary();
            $table->string('title');
            $table->string('artist');
            $table->string('spotify_track_id', 22)->nullable();
            $table->text('spotify_data')->nullable(); // Spotify APIからの追加データ（JSON）
            $table->timestamps();

            $table->index('spotify_track_id');
            $table->index(['title', 'artist']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('songs');
    }
};
