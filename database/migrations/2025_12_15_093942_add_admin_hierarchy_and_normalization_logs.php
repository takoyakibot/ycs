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
        // users テーブルに role カラムを追加
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('user')->after('avatar');
        });

        // timestamp_song_mappings テーブルに操作者カラムを追加
        Schema::table('timestamp_song_mappings', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by')->nullable()->after('confidence');
            $table->unsignedBigInteger('updated_by')->nullable()->after('created_by');

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });

        // songs テーブルに操作者カラムを追加
        Schema::table('songs', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by')->nullable()->after('normalized_artist');
            $table->unsignedBigInteger('updated_by')->nullable()->after('created_by');

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });

        // 正規化操作ログテーブルを作成
        Schema::create('normalization_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->string('action', 30);
            $table->string('target_type', 50);
            $table->string('target_id', 50)->nullable();
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('normalization_logs');

        Schema::table('songs', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropForeign(['updated_by']);
            $table->dropColumn(['created_by', 'updated_by']);
        });

        Schema::table('timestamp_song_mappings', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropForeign(['updated_by']);
            $table->dropColumn(['created_by', 'updated_by']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
