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
        Schema::create('archives', function (Blueprint $table) {
            $table->string('video_id', 11)->primary();
            $table->string('channel_id');
            $table->string('title');
            $table->string('thumbnail')->nullable();
            $table->boolean('is_public')->default(true);
            $table->boolean('is_display')->default(false);
            $table->json('comments');
            $table->date('published_at');
            $table->date('comments_updated_at');
            $table->timestamps();

            $table->index('channel_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('archives');
    }
};
