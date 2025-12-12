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
        Schema::create('timestamp_reports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('video_id', 11);
            $table->string('ts_text', 20)->nullable();
            $table->integer('ts_num')->nullable();
            $table->string('report_type', 20);
            $table->text('comment')->nullable();
            $table->enum('status', ['pending', 'resolved'])->default('pending');
            $table->string('reporter_ip', 45)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['video_id', 'ts_text', 'ts_num'], 'timestamp_reports_composite_key');
            $table->index(['status', 'created_at']);
            $table->index('reporter_ip');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('timestamp_reports');
    }
};
