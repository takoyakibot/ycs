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
        Schema::create('change_list', function (Blueprint $table) {
            $table->id();                                                // id（PK, bigint, auto_increment）
            $table->enum('type', ['archive', 'comment', 'description']); // type（enum: archive, comment, description）
            $table->string('channel_id');                                // channel_id（string, not null）
            $table->string('comment_id')->nullable();                    // comment_id（string, nullable）
            $table->boolean('is_display')->default(true);                // is_display（bool, デフォルト: true）
            $table->timestamps();                                        // created_at, updated_at を自動追加

            // よく使う検索条件に対応するためのインデックス
            $table->index(['channel_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('change_list');
    }
};
