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
        // 同名異表記グループ（同じ曲名で複数名義のマスタが存在するグループ）の
        // レビュー結果（別の曲 / 保留）を記録するテーブル
        Schema::create('song_group_reviews', function (Blueprint $table) {
            $table->string('id', 26)->primary();
            $table->string('normalized_title')->charset('utf8mb4')->collation('utf8mb4_bin');
            $table->string('song_ids_hash', 40)->unique();
            $table->json('song_ids');
            $table->string('decision', 20);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('normalized_title');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('song_group_reviews');
    }
};
