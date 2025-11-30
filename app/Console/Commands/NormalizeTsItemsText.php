<?php

namespace App\Console\Commands;

use App\Helpers\TextNormalizer;
use App\Models\TsItem;
use Illuminate\Console\Command;

class NormalizeTsItemsText extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ts-items:normalize {--chunk=1000 : チャンクサイズ}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'ts_itemsテーブルのnormalized_textカラムを更新';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $chunkSize = (int) $this->option('chunk');
        $total = TsItem::whereNull('normalized_text')->count();

        if ($total === 0) {
            $this->info('更新が必要なレコードはありません。');

            return 0;
        }

        $this->info("更新対象: {$total}件");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $updated = 0;
        TsItem::whereNull('normalized_text')
            ->chunkById($chunkSize, function ($items) use (&$updated, $bar) {
                foreach ($items as $item) {
                    $rawText = $item->getAttributes()['text'] ?? null;
                    $item->normalized_text = TextNormalizer::normalize($rawText);
                    $item->saveQuietly(); // イベントを発火せずに保存
                    $updated++;
                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine();
        $this->info("完了: {$updated}件を更新しました。");

        return 0;
    }
}
