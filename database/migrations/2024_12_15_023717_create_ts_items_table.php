<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ts_items', function (Blueprint $table) {
            $table->id();
            $table->string('archive_id');
            // 1:description, 2:comment
            $table->enum('type', ['1', '2']);
            // HH:MM:SS or MM:SS
            $table->string('ts_text', 8);
            $table->integer('ts_num')->unsigned()->between(1, 43200);
            $table->string('text');
            $table->boolean('is_display')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ts_items');
    }
};
