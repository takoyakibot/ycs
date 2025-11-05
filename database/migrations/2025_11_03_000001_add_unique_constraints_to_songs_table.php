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
        // 1. spotify_track_id の重複を解消
        $this->resolveDuplicateSpotifyTrackIds();

        // 2. title + artist の重複を解消
        $this->resolveDuplicateTitleArtist();

        // 3. ユニーク制約を追加
        Schema::table('songs', function (Blueprint $table) {
            // Spotify Track ID にユニーク制約を追加（NULLは許可）
            $table->unique('spotify_track_id', 'songs_spotify_track_id_unique');

            // Title + Artist の複合ユニーク制約を追加
            $table->unique(['title', 'artist'], 'songs_title_artist_unique');
        });
    }

    /**
     * spotify_track_id の重複を解消
     */
    private function resolveDuplicateSpotifyTrackIds(): void
    {
        // 重複している spotify_track_id を取得
        $duplicates = DB::table('songs')
            ->select('spotify_track_id', DB::raw('MIN(id) as keep_id'))
            ->whereNotNull('spotify_track_id')
            ->groupBy('spotify_track_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            // 最も古いレコード以外を削除
            DB::table('songs')
                ->where('spotify_track_id', $duplicate->spotify_track_id)
                ->where('id', '!=', $duplicate->keep_id)
                ->delete();

            echo "Removed duplicate spotify_track_id: {$duplicate->spotify_track_id}\n";
        }
    }

    /**
     * title + artist の重複を解消
     */
    private function resolveDuplicateTitleArtist(): void
    {
        // 重複している title + artist を取得
        $duplicates = DB::table('songs')
            ->select('title', 'artist', DB::raw('MIN(id) as keep_id'))
            ->groupBy('title', 'artist')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            // 最も古いレコード以外を削除
            DB::table('songs')
                ->where('title', $duplicate->title)
                ->where('artist', $duplicate->artist)
                ->where('id', '!=', $duplicate->keep_id)
                ->delete();

            echo "Removed duplicate title+artist: {$duplicate->title} / {$duplicate->artist}\n";
        }
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
