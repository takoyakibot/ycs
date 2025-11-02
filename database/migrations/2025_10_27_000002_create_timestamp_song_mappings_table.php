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
        Schema::create('timestamp_song_mappings', function (Blueprint $table) {
            $table->string('id', 26)->primary();
            $table->string('normalized_text')->unique(); // 正規化されたタイムスタンプテキスト
            $table->string('song_id', 26)->nullable(); // 楽曲マスタへの参照
            $table->boolean('is_not_song')->default(false); // 楽曲ではないフラグ
            $table->boolean('is_manual')->default(false); // 手動での紐づけか
            $table->float('confidence')->default(1.0); // あいまい検索用のスコア（1.0 = 完全一致）
            $table->timestamps();

            $table->foreign('song_id')->references('id')->on('songs')
                ->onDelete('set null');
            $table->index('song_id');
            $table->index('is_not_song');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('timestamp_song_mappings');
    }
};
