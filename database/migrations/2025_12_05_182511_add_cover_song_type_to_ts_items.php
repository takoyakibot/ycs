<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * ts_items.type に '3' (カバー曲/歌ってみた) を追加
     * 1: 概要欄, 2: コメント, 3: カバー曲
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            // MySQLの場合: enumに '3' を追加
            DB::statement("ALTER TABLE ts_items MODIFY COLUMN type ENUM('1', '2', '3') NOT NULL");
        }
        // SQLiteの場合: enumはTEXTとして扱われるため、変更不要
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            // MySQLの場合: enumを元に戻す（type='3'のレコードは先に削除が必要）
            DB::statement("ALTER TABLE ts_items MODIFY COLUMN type ENUM('1', '2') NOT NULL");
        }
    }
};
