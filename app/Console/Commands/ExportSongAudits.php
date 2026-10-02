<?php

namespace App\Console\Commands;

use App\Models\SongAudit;
use App\Services\SongAuditService;
use Illuminate\Console\Command;

class ExportSongAudits extends Command
{
    protected $signature = 'song-audit:export
        {type : 点検対象（song: 楽曲マスタ / mapping: 紐付け）}
        {--limit=50 : 出力する最大件数}';

    protected $description = '未点検（または点検後に内容が変わった）楽曲マスタ・紐付けを JSON で出力する';

    public function handle(SongAuditService $service): int
    {
        $type = $this->argument('type');
        if (! in_array($type, [SongAudit::TARGET_SONG, SongAudit::TARGET_MAPPING], true)) {
            $this->error('type には song か mapping を指定してください');

            return Command::FAILURE;
        }

        $limit = (int) $this->option('limit');
        if ($limit < 1) {
            $this->error('--limit には1以上を指定してください');

            return Command::FAILURE;
        }

        $this->line(json_encode(
            $service->export($type, $limit),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        ));

        return Command::SUCCESS;
    }
}
