<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_strip_patterns', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('channel_id');
            $table->string('pattern');
            $table->timestamps();

            $table->foreign('channel_id')
                ->references('channel_id')
                ->on('channels')
                ->onDelete('cascade');

            $table->unique(['channel_id', 'pattern']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_strip_patterns');
    }
};
