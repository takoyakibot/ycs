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
        } else {
            // SQLiteの場合: テーブル再作成でCHECK制約を更新
            // SQLiteはALTER TABLE MODIFY COLUMNをサポートしないため
            // 外部キー制約を一時的に無効化
            DB::statement('PRAGMA foreign_keys=OFF');

            DB::statement('CREATE TABLE ts_items_new (
                id VARCHAR(26) PRIMARY KEY,
                video_id VARCHAR(11) NOT NULL,
                comment_id VARCHAR(255),
                type VARCHAR(255) NOT NULL CHECK (type IN (\'1\', \'2\', \'3\')),
                ts_text VARCHAR(8) NOT NULL,
                ts_num INTEGER NOT NULL,
                text VARCHAR(255) NOT NULL,
                normalized_text VARCHAR(255),
                is_display BOOLEAN DEFAULT 1,
                created_at TIMESTAMP,
                updated_at TIMESTAMP,
                FOREIGN KEY (video_id) REFERENCES archives(video_id) ON DELETE CASCADE
            )');

            DB::statement('INSERT INTO ts_items_new SELECT * FROM ts_items');
            DB::statement('DROP TABLE ts_items');
            DB::statement('ALTER TABLE ts_items_new RENAME TO ts_items');

            // インデックスを再作成
            DB::statement('CREATE INDEX ts_items_video_id_ts_num_index ON ts_items (video_id, ts_num)');

            // 外部キー制約を再度有効化
            DB::statement('PRAGMA foreign_keys=ON');
        }
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
        } else {
            // SQLiteの場合: テーブル再作成
            DB::statement('PRAGMA foreign_keys=OFF');

            DB::statement('CREATE TABLE ts_items_new (
                id VARCHAR(26) PRIMARY KEY,
                video_id VARCHAR(11) NOT NULL,
                comment_id VARCHAR(255),
                type VARCHAR(255) NOT NULL CHECK (type IN (\'1\', \'2\')),
                ts_text VARCHAR(8) NOT NULL,
                ts_num INTEGER NOT NULL,
                text VARCHAR(255) NOT NULL,
                normalized_text VARCHAR(255),
                is_display BOOLEAN DEFAULT 1,
                created_at TIMESTAMP,
                updated_at TIMESTAMP,
                FOREIGN KEY (video_id) REFERENCES archives(video_id) ON DELETE CASCADE
            )');

            DB::statement('INSERT INTO ts_items_new SELECT * FROM ts_items WHERE type IN (\'1\', \'2\')');
            DB::statement('DROP TABLE ts_items');
            DB::statement('ALTER TABLE ts_items_new RENAME TO ts_items');

            DB::statement('CREATE INDEX ts_items_video_id_ts_num_index ON ts_items (video_id, ts_num)');

            DB::statement('PRAGMA foreign_keys=ON');
        }
    }
};
