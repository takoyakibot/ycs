<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 楽曲マスタ・紐付けの点検結果（Claude Code 等による判定）を記録するテーブル
        Schema::create('song_audits', function (Blueprint $table) {
            $table->string('id', 26)->primary();
            $table->string('target_type', 20);
            $table->string('target_id', 26);
            // 判定時の対象内容のハッシュ。現在の内容と食い違えば判定後に更新されたとみなす
            $table->char('fingerprint', 40);
            $table->string('verdict', 20);
            $table->text('reason')->nullable();
            $table->json('suggestion')->nullable();
            $table->string('judged_by', 50);
            $table->timestamp('judged_at');
            $table->string('resolution', 20)->default('pending');
            $table->timestamps();

            $table->unique(['target_type', 'target_id']);
            $table->index(['verdict', 'resolution']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('song_audits');
    }
};
