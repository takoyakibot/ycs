<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spectral_scan_data', function (Blueprint $table) {
            $table->string('id', 26)->primary();
            $table->string('video_id', 11)->unique();
            $table->integer('sampling_interval');
            $table->float('duration');
            $table->longText('spectral_data');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spectral_scan_data');
    }
};
