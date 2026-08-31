<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('song_tags', function (Blueprint $table) {
            $table->string('id', 26)->primary();
            $table->string('song_id', 26);
            $table->string('value', 255);
            $table->timestamps();

            $table->foreign('song_id')
                ->references('id')
                ->on('songs')
                ->onDelete('cascade');

            $table->index('song_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('song_tags');
    }
};
