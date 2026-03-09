<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 絵文字対応: テーブルのcharsetをutf8mb4に変換
     *
     * MySQLのデフォルトcharsetがutf8(3バイト)の場合、
     * 絵文字(4バイトUTF-8)が「??」に化けるため、
     * utf8mb4に変換して絵文字を正しく保存できるようにする。
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
