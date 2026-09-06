<?php

namespace App\Console\Commands;

use App\Models\TimestampDecomposition;
use App\Models\TimestampSongMapping;
use App\Services\TimestampDecompositionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResetUnlinkedDecompositions extends Command
{
    protected $signature = 'ts-decompositions:reset-unlinked
        {--apply : 実際に削除・再スキャンする（指定しない場合はドライラン）}
        {--skip-rescan : 削除のみ行い再スキャンはスキップする}';

    protected $description = '未紐付けのTS分解レコードを削除し、改善されたロジックで再スキャンする（既定はドライラン）';

    public function handle(TimestampDecompositionService $service): int
    {
        $apply = (bool) $this->option('apply');
        $skipRescan = (bool) $this->option('skip-rescan');

        $this->line('モード: '.($apply ? '<comment>APPLY（削除・再スキャンします）</comment>' : '<info>ドライラン（削除しません）</info>'));
        $this->newLine();

        $targets = $this->getUnlinkedDecompositions();

        $this->line("対象件数: <info>{$targets->count()}</info>");
        $this->newLine();

        $byStatus = $targets->groupBy('status');
        $this->table(
            ['ステータス', '件数'],
            $byStatus->map(fn ($items, $status) => [$status, $items->count()])->values()
        );

        $reviewedWithTitle = $targets->filter(
            fn ($d) => $d->status === TimestampDecomposition::STATUS_SELECTED && $d->derived_title !== null
        );
        if ($reviewedWithTitle->isNotEmpty()) {
            $this->warn("うち曲名確定済み（アーティスト未設定）のSELECTED: {$reviewedWithTitle->count()}件");
        }
        $this->newLine();

        if ($targets->isEmpty()) {
            $this->info('リセット対象のレコードはありません。');

            return self::SUCCESS;
        }

        $this->line('<comment>サンプル（最大20件）:</comment>');
        $this->table(
            ['ID', 'ステータス', 'テキスト', 'パーツ数'],
            $targets->take(20)->map(fn ($d) => [
                substr($d->id, 0, 10).'…',
                $d->status,
                mb_strimwidth($d->original_text, 0, 50, '…'),
                $d->separator_count + 1,
            ])
        );

        if (! $apply) {
            $this->newLine();
            $this->info('ドライランのため削除は行いません。--apply を付けて実行してください。');

            return self::SUCCESS;
        }

        $deletedCount = DB::transaction(function () use ($targets) {
            $ids = $targets->pluck('id')->toArray();

            return TimestampDecomposition::whereIn('id', $ids)->delete();
        });

        $this->info("削除完了: <comment>{$deletedCount}件</comment>");

        if ($skipRescan) {
            $this->line('再スキャンはスキップされました。手動で scan エンドポイントを呼ぶか、再度このコマンドを実行してください。');

            return self::SUCCESS;
        }

        $this->line('再スキャン中...');
        try {
            $scannedCount = $service->scanAndDecompose();
            $this->info("再スキャン完了: <comment>{$scannedCount}件</comment> が新たにTS分解対象に追加されました");
        } catch (\Throwable $e) {
            $this->error("再スキャンに失敗しました: {$e->getMessage()}");
            $this->line('削除は完了しています。TS分解画面のスキャンボタン、または --skip-rescan なしで再実行してください。');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * 未紐付け（確定済みsong_idなし）のTS分解レコードを取得
     */
    private function getUnlinkedDecompositions()
    {
        return TimestampDecomposition::where('status', '!=', TimestampDecomposition::STATUS_PENDING)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('timestamp_song_mappings')
                    ->whereColumn('timestamp_song_mappings.normalized_text', 'timestamp_decompositions.normalized_text')
                    ->whereNotNull('timestamp_song_mappings.song_id')
                    ->where(TimestampSongMapping::confirmedJoinConditions());
            })
            ->get();
    }
}
