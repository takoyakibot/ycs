<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_replay_data', function (Blueprint $table) {
            $table->string('id', 26)->primary();
            $table->string('video_id', 11)->unique();
            $table->float('duration');
            $table->unsignedInteger('message_count');
            $table->json('chat_data');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_replay_data');
    }
};
