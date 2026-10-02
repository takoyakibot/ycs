<?php

namespace App\Console\Commands;

use App\Services\SongAuditService;
use Illuminate\Console\Command;

class ImportSongAudits extends Command
{
    protected $signature = 'song-audit:import
        {file? : 判定結果の JSON ファイル（省略時は標準入力から読む）}
        {--judged-by=claude : 判定者}
        {--dry-run : 検証のみ行い登録しない}';

    protected $description = '楽曲マスタ・紐付けの点検結果（JSON）を検証して判定テーブルに登録する';

    public function handle(SongAuditService $service): int
    {
        $file = $this->argument('file');
        if ($file !== null && ! is_file($file)) {
            $this->error("ファイルが見つかりません: {$file}");

            return Command::FAILURE;
        }

        $json = $file !== null ? file_get_contents($file) : stream_get_contents(STDIN);
        $items = json_decode($json, true);
        if (! is_array($items) || ! array_is_list($items)) {
            $this->error('JSON は判定結果の配列で渡してください');

            return Command::FAILURE;
        }

        $judgedBy = (string) $this->option('judged-by');
        if ($judgedBy === '' || mb_strlen($judgedBy) > 50) {
            $this->error('--judged-by は1〜50文字で指定してください');

            return Command::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $summary = $service->import($items, $judgedBy, $dryRun);

        foreach ($summary['stale'] as $message) {
            $this->warn($message);
        }
        foreach ($summary['errors'] as $message) {
            $this->error($message);
        }

        $verb = $dryRun ? '登録可能' : '登録';
        $this->info(sprintf(
            '%s: %d件 / 更新済みのため除外: %d件 / エラー: %d件',
            $verb,
            $summary['registered'],
            count($summary['stale']),
            count($summary['errors'])
        ));
        if ($dryRun) {
            $this->warn('--dry-run: 登録は行われていません');
        }

        return empty($summary['errors']) ? Command::SUCCESS : Command::FAILURE;
    }
}
