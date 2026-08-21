<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * video_subtitles の archives への外部キーを撤去する（#622）。
     *
     * アーカイブ更新（RefreshArchiveService）はDELETE→再INSERT方式のため、
     * cascadeOnDelete だと更新のたびに収集済みの字幕が全滅していた。
     * video_id はYouTube由来の安定IDなので、アーカイブ再作成後も対応が取れる。
     *
     * 既存DB（MySQL）向け。SQLite（テスト）はcreate migration側の修正で
     * 最初からFKなしで作られるため何もしない。
     *
     * create migration側がFKを作らないよう編集されたため、新規MySQL環境では
     * このFKは最初から存在しない。ドライバ判定だけで dropForeign すると
     * errno 1091 で migrate 全体が停止するので、FKの存在を確認してから落とす（#653）。
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        $fkExists = DB::selectOne(
            'SELECT 1 AS x FROM information_schema.table_constraints
             WHERE table_schema = DATABASE() AND table_name = ?
               AND constraint_name = ? AND constraint_type = ?',
            ['video_subtitles', 'video_subtitles_video_id_foreign', 'FOREIGN KEY']
        );

        if ($fkExists === null) {
            return;
        }

        Schema::table('video_subtitles', function (Blueprint $table) {
            $table->dropForeign(['video_id']);
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('video_subtitles', function (Blueprint $table) {
            $table->foreign('video_id')
                ->references('video_id')
                ->on('archives')
                ->cascadeOnDelete();
        });
    }
};
