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
        Schema::create('timestamp_decompositions', function (Blueprint $table) {
            $table->string('id', 26)->primary(); // ULID
            $table->string('normalized_text', 255)->unique(); // ts_itemsとの紐付けキー
            $table->text('original_text'); // 元テキスト
            $table->json('parts'); // 分解パーツ ["A", "B", "C"]
            $table->unsignedTinyInteger('separator_count')->default(0); // 区切り文字数

            // 選別結果
            $table->unsignedTinyInteger('title_part_index')->nullable(); // 楽曲名パーツのインデックス
            $table->unsignedTinyInteger('artist_part_index')->nullable(); // アーティスト名パーツのインデックス
            $table->string('derived_title', 255)->nullable(); // 確定した楽曲名
            $table->string('derived_artist', 255)->nullable(); // 確定したアーティスト名

            // ステータス
            $table->enum('status', ['pending', 'selected', 'skipped', 'auto_matched'])->default('pending');
            $table->float('confidence')->nullable(); // 自動判定の確信度

            // 紐付け結果
            $table->string('song_id', 26)->nullable();

            $table->string('created_by', 26)->nullable();
            $table->string('updated_by', 26)->nullable();
            $table->timestamps();

            $table->foreign('song_id')->references('id')->on('songs')->onDelete('set null');
            $table->index('status');
            $table->index('separator_count');
            $table->index('normalized_text');
            // getNextPending()クエリ用の複合インデックス
            $table->index(['status', 'separator_count', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('timestamp_decompositions');
    }
};
