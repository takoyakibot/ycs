<?php

namespace App\Console\Commands;

use App\Services\AutoLinkService;
use Illuminate\Console\Command;

class AutoLinkSongs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'songs:auto-link
                            {--limit=100 : 処理件数上限}
                            {--dry-run : 実際の処理を行わず、対象件数のみ表示}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '未紐付けのタイムスタンプをSpotify APIで自動検索し、トップ結果を紐付けます。';

    /**
     * Execute the console command.
     */
    public function handle(AutoLinkService $autoLinkService): int
    {
        $limit = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');

        $this->info('=== 楽曲自動紐付け処理 ===');
        $this->info(sprintf('処理件数上限: %d件', $limit));

        if ($dryRun) {
            $this->warn('ドライランモード: 実際の処理は行いません');

            return $this->runDryRun($limit);
        }

        return $this->runAutoLink($autoLinkService, $limit);
    }

    /**
     * ドライラン実行
     */
    protected function runDryRun(int $limit): int
    {
        // 未紐付け件数を取得
        $count = \App\Models\TsItem::select('ts_items.normalized_text')
            ->leftJoin('timestamp_song_mappings', 'ts_items.normalized_text', '=', 'timestamp_song_mappings.normalized_text')
            ->whereNotNull('ts_items.text')
            ->where('ts_items.text', '!=', '')
            ->whereNotNull('ts_items.normalized_text')
            ->where('ts_items.is_display', 1)
            ->whereHas('archive', function ($q) {
                $q->where('is_display', 1);
            })
            ->whereNull('timestamp_song_mappings.id')
            ->distinct()
            ->count('ts_items.normalized_text');

        $this->info(sprintf('未紐付けのユニークテキスト数: %d件', $count));
        $this->info(sprintf('処理対象: %d件', min($count, $limit)));

        return 0;
    }

    /**
     * 自動紐付け実行
     */
    protected function runAutoLink(AutoLinkService $autoLinkService, int $limit): int
    {
        $startTime = microtime(true);

        $result = $autoLinkService->autoLinkUnlinkedTimestamps($limit, function ($message) {
            $this->line($message);
        });

        $duration = round(microtime(true) - $startTime, 2);

        $this->newLine();
        $this->info('=== 処理結果 ===');
        $this->table(
            ['項目', '件数'],
            [
                ['処理件数', $result['processed']],
                ['紐付け成功', $result['linked']],
                ['検索結果なし/エラー', $result['failed']],
                ['スキップ（類似曲あり）', $result['skipped']],
            ]
        );
        $this->info(sprintf('処理時間: %s秒', $duration));

        return 0;
    }
}
