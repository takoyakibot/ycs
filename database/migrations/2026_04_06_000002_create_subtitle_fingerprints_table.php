<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subtitle_fingerprints', function (Blueprint $table) {
            $table->string('id', 26)->primary();
            $table->string('video_id', 11)->index();
            $table->string('ts_item_id', 26)->unique();
            $table->unsignedInteger('start_sec');
            $table->unsignedSmallInteger('duration_sec')->default(30);
            $table->text('fingerprint_text');
            $table->json('trigrams');
            $table->timestamps();

            $table->foreign('video_id')
                ->references('video_id')
                ->on('archives')
                ->cascadeOnDelete();

            $table->foreign('ts_item_id')
                ->references('id')
                ->on('ts_items')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subtitle_fingerprints');
    }
};
