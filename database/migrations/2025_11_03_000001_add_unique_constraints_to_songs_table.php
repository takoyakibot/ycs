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
        Schema::table('songs', function (Blueprint $table) {
            // Spotify Track ID にユニーク制約を追加（NULLは許可）
            $table->unique('spotify_track_id', 'songs_spotify_track_id_unique');

            // Title + Artist の複合ユニーク制約を追加
            $table->unique(['title', 'artist'], 'songs_title_artist_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('songs', function (Blueprint $table) {
            $table->dropUnique('songs_spotify_track_id_unique');
            $table->dropUnique('songs_title_artist_unique');
        });
    }
};
