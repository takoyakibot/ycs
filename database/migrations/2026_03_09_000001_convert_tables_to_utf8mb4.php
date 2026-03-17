<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 絵文字対応: テーブルのcharsetをutf8mb4に変換
     *
     * Laravelの接続設定(config/database.php)は既にutf8mb4だが、
     * テーブル自体のデフォルトcharsetがutf8(3バイト)で作成されている場合、
     * カラム定義がutf8のままとなり絵文字(4バイトUTF-8)が正しく保存できない。
     * このマイグレーションでテーブルとカラムのcharsetをutf8mb4に統一する。
     *
     * 注意: CONVERT TO CHARACTER SETはテーブル全体のコピーが発生し、
     * 実行中はテーブルにメタデータロックがかかるため、
     * データ量が多いテーブル(ts_items, archives等)ではダウンタイムが発生する。
     * 本番適用時はメンテナンスウィンドウで実施すること。
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $tables = [
            'archives',
            'ts_items',
            'channels',
            'songs',
            'timestamp_song_mappings',
            'change_list',
            'timestamp_reports',
        ];

        foreach ($tables as $table) {
            DB::statement(
                "ALTER TABLE `{$table}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // charsetの変換は元に戻さない（データロスの可能性があるため）
    }
};
