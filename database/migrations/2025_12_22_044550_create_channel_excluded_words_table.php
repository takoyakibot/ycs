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
        Schema::create('channel_excluded_words', function (Blueprint $table) {
            $table->string('id', 26)->primary(); // ULID
            $table->string('channel_id', 255);
            $table->string('word', 255);
            $table->timestamps();

            $table->foreign('channel_id')
                ->references('channel_id')
                ->on('channels')
                ->onDelete('cascade');

            $table->unique(['channel_id', 'word'], 'unique_channel_word');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channel_excluded_words');
    }
};
