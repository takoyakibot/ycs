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
    protected $description = '未紐付けのタイムスタンプを既存楽曲マスタと照合して自動紐付けします。';

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
     *
     * 実際の紐付けは行わず、現在の閾値で何件が自動紐付けされ、
     * 何件が候補提示に留まるかを実データで集計する。
     */
    protected function runDryRun(int $limit): int
    {
        $autoLinkService = app(AutoLinkService::class);

        // 未紐付け件数を取得
        $count = \App\Models\TsItem::select('ts_items.normalized_text')
            ->leftJoin('timestamp_song_mappings', 'ts_items.normalized_text', '=', 'timestamp_song_mappings.normalized_text')
            ->whereNotNull('ts_items.text')
            ->where('ts_items.text', '!=', '')
            ->whereNotNull('ts_items.normalized_text')
            ->where('ts_items.is_display', 1)
            ->where('ts_items.type', '!=', '3') // 歌ってみた/カバー曲はノイズが多いため除外
            ->whereHas('archive', function ($q) {
                $q->where('is_display', 1);
            })
            ->whereNull('timestamp_song_mappings.id')
            ->distinct()
            ->count('ts_items.normalized_text');

        $this->info(sprintf('未紐付けのユニークテキスト数: %d件', $count));
        $this->info(sprintf('照合対象: %d件', min($count, $limit)));
        $this->newLine();

        $autoThreshold = (float) config('songs.matching.auto_link_threshold', 0.85);
        $candidateThreshold = (float) config('songs.matching.candidate_threshold', 0.5);
        $this->line(sprintf('自動紐付け閾値: %.2f / 候補提示閾値: %.2f', $autoThreshold, $candidateThreshold));

        $startTime = microtime(true);
        $summary = $autoLinkService->analyzeUnlinkedTimestamps($limit);
        $duration = round(microtime(true) - $startTime, 2);

        $total = max(1, $summary['total']);
        $this->newLine();
        $this->info('=== 照合結果 ===');
        $this->table(
            ['区分', '件数', '割合'],
            [
                ['自動紐付け可', $summary['auto_linkable'], sprintf('%.1f%%', $summary['auto_linkable'] / $total * 100)],
                ['候補提示のみ', $summary['candidate_only'], sprintf('%.1f%%', $summary['candidate_only'] / $total * 100)],
                ['一致なし', $summary['no_match'], sprintf('%.1f%%', $summary['no_match'] / $total * 100)],
                ['（うち曖昧で保留）', $summary['ambiguous'], '-'],
            ]
        );

        if (! empty($summary['by_confidence'])) {
            $this->newLine();
            $this->info('=== 信頼度の分布（最有力候補） ===');
            $rows = [];
            foreach ($summary['by_confidence'] as $confidence => $confidenceCount) {
                $rows[] = [$confidence, $confidenceCount];
            }
            $this->table(['信頼度', '件数'], $rows);
        }

        if (! empty($summary['by_source'])) {
            $this->newLine();
            $this->info('=== 照合の由来（最有力候補） ===');
            $rows = [];
            foreach ($summary['by_source'] as $source => $sourceCount) {
                $rows[] = [$source === 'dictionary' ? '既存の紐付け表記' : '楽曲マスタ', $sourceCount];
            }
            $this->table(['由来', '件数'], $rows);
        }

        if (! empty($summary['samples'])) {
            $this->newLine();
            $this->info('=== 照合サンプル ===');
            $rows = [];
            foreach ($summary['samples'] as $sample) {
                $rows[] = [
                    mb_strimwidth($sample['text'], 0, 40, '…'),
                    mb_strimwidth($sample['title'].' / '.$sample['artist'], 0, 34, '…'),
                    number_format($sample['confidence'], 2),
                    $sample['coverage'] === null ? '-' : sprintf('%.0f%%', $sample['coverage'] * 100),
                    $sample['artist_hit'] ? '○' : '',
                    $sample['source'] === 'dictionary' ? '既存表記' : 'マスタ',
                ];
            }
            $this->table(['元テキスト', '候補', '信頼度', '被覆率', 'artist', '由来'], $rows);
        }

        if (! empty($summary['no_match_samples'])) {
            $this->newLine();
            $this->info('=== 一致なしのサンプル ===');
            foreach ($summary['no_match_samples'] as $text) {
                $this->line('  - '.mb_strimwidth($text, 0, 70, '…'));
            }
        }

        $this->newLine();
        $this->info(sprintf('照合時間: %s秒', $duration));

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
                ['一致なし', $result['skipped']],
                ['エラー', $result['failed']],
            ]
        );
        $this->info(sprintf('処理時間: %s秒', $duration));

        return 0;
    }
}
