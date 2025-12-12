<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * ts_item_idから複合キー(video_id+ts_text+ts_num)への移行
     * 注: 元のマイグレーションが既に新スキーマの場合はスキップ
     */
    public function up(): void
    {
        // 既に新スキーマの場合（ts_textカラムが存在する場合）はスキップ
        if (Schema::hasColumn('timestamp_reports', 'ts_text')) {
            return;
        }

        Schema::table('timestamp_reports', function (Blueprint $table) {
            // 新しいカラムを追加
            $table->string('ts_text', 20)->nullable()->after('video_id');
            $table->integer('ts_num')->nullable()->after('ts_text');
        });

        // 既存データのts_text, ts_numを埋める（ts_item_idから取得）
        DB::statement('
            UPDATE timestamp_reports r
            INNER JOIN ts_items t ON r.ts_item_id = t.id
            SET r.ts_text = t.ts_text, r.ts_num = t.ts_num
        ');

        Schema::table('timestamp_reports', function (Blueprint $table) {
            // ts_item_idを削除し、インデックスを追加
            $table->dropForeign(['ts_item_id']);
            $table->dropColumn('ts_item_id');
            $table->index(['video_id', 'ts_text', 'ts_num'], 'timestamp_reports_composite_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // ts_item_idが存在しない場合のみロールバック
        if (Schema::hasColumn('timestamp_reports', 'ts_item_id')) {
            return;
        }

        Schema::table('timestamp_reports', function (Blueprint $table) {
            $table->dropIndex('timestamp_reports_composite_key');
            $table->ulid('ts_item_id')->nullable()->after('id');
        });

        // ts_item_idを復元（ts_text, ts_numからマッチするts_itemを探す）
        DB::statement('
            UPDATE timestamp_reports r
            INNER JOIN ts_items t ON r.video_id = t.video_id
                AND r.ts_text = t.ts_text
                AND r.ts_num = t.ts_num
            SET r.ts_item_id = t.id
        ');

        Schema::table('timestamp_reports', function (Blueprint $table) {
            $table->dropColumn(['ts_text', 'ts_num']);
            $table->foreign('ts_item_id')->references('id')->on('ts_items')->onDelete('cascade');
        });
    }
};
