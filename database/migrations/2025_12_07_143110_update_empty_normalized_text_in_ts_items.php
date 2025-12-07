<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * normalized_text が空文字列の場合、元のtextを小文字化して設定
     * これにより timestamp_song_mappings との JOIN が正しく動作する
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            // MySQLの場合: LOWER(TRIM(text)) で更新
            DB::statement("
                UPDATE ts_items
                SET normalized_text = LOWER(TRIM(text))
                WHERE normalized_text = ''
                  AND text IS NOT NULL
                  AND TRIM(text) != ''
            ");
        } else {
            // SQLiteの場合: 同様の更新
            DB::statement("
                UPDATE ts_items
                SET normalized_text = LOWER(TRIM(text))
                WHERE normalized_text = ''
                  AND text IS NOT NULL
                  AND TRIM(text) != ''
            ");
        }
    }

    /**
     * Reverse the migrations.
     *
     * 元の空文字列に戻すことは難しいため、何もしない
     */
    public function down(): void
    {
        // 元に戻すことは困難なため、何もしない
    }
};
