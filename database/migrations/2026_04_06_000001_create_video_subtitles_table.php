<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_subtitles', function (Blueprint $table) {
            $table->string('id', 26)->primary();
            $table->string('video_id', 11)->index();
            $table->string('language_code', 20);
            $table->string('kind', 10)->default('');
            $table->longText('subtitle_data');
            $table->unsignedInteger('segment_count');
            $table->timestamps();

            $table->unique(['video_id', 'language_code', 'kind']);

            $table->foreign('video_id')
                ->references('video_id')
                ->on('archives')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_subtitles');
    }
};
