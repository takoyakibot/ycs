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
            $table->id();
            $table->string('title'); // 楽曲名
            $table->string('artist'); // アーティスト名
            $table->string('spotify_id')->nullable(); // Spotify ID
            $table->string('spotify_uri')->nullable(); // Spotify URI
            $table->text('spotify_preview_url')->nullable(); // プレビューURL
            $table->json('spotify_data')->nullable(); // Spotify APIから取得した追加データ
            $table->timestamps();
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
