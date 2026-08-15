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
            $table->json('subtitle_data');
            $table->unsignedInteger('segment_count');
            $table->timestamps();

            $table->unique(['video_id', 'language_code', 'kind']);

            // archivesへの外部キーは張らない（#622）。
            // アーカイブ更新はDELETE→再INSERT方式のため、cascadeにすると
            // 更新のたびに収集済みの字幕が全滅する。video_idはYouTube由来の
            // 安定IDなので、アーカイブ再作成後もそのまま対応が取れる
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_subtitles');
    }
};
