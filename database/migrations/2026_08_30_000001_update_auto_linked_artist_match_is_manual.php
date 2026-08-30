<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 自動紐付け済み（is_manual=false, status=linked）のうち、
        // アーティスト名が一致するものを確定扱い（is_manual=true）に変更する。
        //
        // 判定: songs.normalized_artist が空でなく、
        //       timestamp_song_mappings.normalized_text に songs.normalized_artist が含まれる
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("
            UPDATE timestamp_song_mappings tsm
            JOIN songs s ON tsm.song_id = s.id
            SET tsm.is_manual = 1,
                tsm.confidence = 0.9
            WHERE tsm.is_manual = 0
              AND tsm.status = 'linked'
              AND s.normalized_artist IS NOT NULL
              AND s.normalized_artist != ''
              AND tsm.normalized_text LIKE CONCAT('%', s.normalized_artist, '%')
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // down では confidence=0.9 を目印にして元に戻す。
        // 注意: AutoLinkService もアーティスト一致時に confidence=0.9 を設定するため、
        // デプロイ後に新規作成された確定済みレコードも巻き戻される可能性がある。
        // ワンタイムのデータ移行であり、ロールバックは最善努力。
        DB::statement("
            UPDATE timestamp_song_mappings
            SET is_manual = 0,
                confidence = 0.8
            WHERE is_manual = 1
              AND status = 'linked'
              AND confidence = 0.9
        ");
    }
};
