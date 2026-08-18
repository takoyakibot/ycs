<?php

namespace App\Console\Commands;

use App\Models\TimestampDecomposition;
use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use Illuminate\Console\Command;

/**
 * normalized_text に半角カナの全角化を加えた場合の衝突を調べる
 *
 * TextNormalizer::normalize() は mb_convert_kana に 'as' しか渡していないため、
 * 半角カナが全角カナに寄らない。そのため「ｲｴｽﾀﾃﾞｲ」と「イエスタデイ」は
 * 別の normalized_text として保存される。
 *
 * これを揃える（NFKC と同じ方向。'KV' を追加する）と、これまで別レコードとして
 * 共存していた行が同じ値になる。timestamp_song_mappings.normalized_text と
 * timestamp_decompositions.normalized_text は UNIQUE なので、
 * 衝突する行のマージ規則を決めないと再正規化マイグレーションが落ちる。
 *
 * このコマンドは読み取りのみで、規模とマージ規則の判断材料を出す。
 */
class AnalyzeKanaCollisions extends Command
{
    protected $signature = 'normalized-text:analyze-kana-collisions
                            {--chunk=1000 : チャンクサイズ}
                            {--csv= : 衝突の詳細をCSVに出力するパス}';

    protected $description = 'normalized_text を半角カナ→全角カナに揃えた場合のUNIQUE衝突を調べる（読み取りのみ）';

    /** @var array<int, array<string, string>> CSV出力用の行 */
    private array $csvRows = [];

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));

        $this->line('normalized_text に半角カナの全角化（mb_convert_kana の KV）を加えた場合の影響を調べます。');
        $this->line('このコマンドはデータを変更しません。');
        $this->newLine();

        $mappings = $this->analyzeMappings($chunk);
        $decompositions = $this->analyzeDecompositions($chunk);
        $tsItems = $this->analyzeTsItems($chunk);

        $this->renderSummary($mappings, $decompositions, $tsItems);
        $this->renderVerdict($mappings, $decompositions);

        if ($path = $this->option('csv')) {
            $this->exportCsv((string) $path);
        }

        return self::SUCCESS;
    }

    /**
     * 半角カナを全角カナに寄せた値を返す
     *
     * 保存済みの normalized_text は既に normalize() を通っているため、
     * ここでは KV だけを追加で適用する。normalize() 内に KV を足した場合と
     * 同じ結果になることは ChangeKanaCollisionAnalysisTest で確認している。
     */
    public static function toFullWidthKana(?string $value): string
    {
        return mb_convert_kana((string) $value, 'KV', 'UTF-8');
    }

    /**
     * @return array{total: int, changed: int, groups: array<string, array<int, object>>}
     */
    private function analyzeMappings(int $chunk): array
    {
        $total = 0;
        $changed = 0;
        $byNewValue = [];

        TimestampSongMapping::query()
            ->select(['id', 'normalized_text', 'song_id', 'is_not_song', 'is_manual'])
            ->orderBy('id')
            ->chunkById($chunk, function ($rows) use (&$total, &$changed, &$byNewValue) {
                foreach ($rows as $row) {
                    $total++;
                    $new = self::toFullWidthKana($row->normalized_text);

                    if ($new !== $row->normalized_text) {
                        $changed++;
                    }

                    $byNewValue[$new][] = $row;
                }
            });

        return [
            'total' => $total,
            'changed' => $changed,
            'groups' => array_filter($byNewValue, fn ($rows) => count($rows) > 1),
        ];
    }

    /**
     * @return array{total: int, changed: int, groups: array<string, array<int, object>>}
     */
    private function analyzeDecompositions(int $chunk): array
    {
        $total = 0;
        $changed = 0;
        $byNewValue = [];

        TimestampDecomposition::query()
            ->select(['id', 'normalized_text', 'song_id', 'status', 'derived_title', 'derived_artist'])
            ->orderBy('id')
            ->chunkById($chunk, function ($rows) use (&$total, &$changed, &$byNewValue) {
                foreach ($rows as $row) {
                    $total++;
                    $new = self::toFullWidthKana($row->normalized_text);

                    if ($new !== $row->normalized_text) {
                        $changed++;
                    }

                    $byNewValue[$new][] = $row;
                }
            });

        return [
            'total' => $total,
            'changed' => $changed,
            'groups' => array_filter($byNewValue, fn ($rows) => count($rows) > 1),
        ];
    }

    /**
     * ts_items は UNIQUE 制約が無いので衝突は起きない。
     * 値が変わる件数と、変換で新たにマッピングに一致する件数を見る。
     *
     * @return array{total: int, changed: int, newly_linkable: int}
     */
    private function analyzeTsItems(int $chunk): array
    {
        // 変換後のマッピングキー集合（変換後の値で引けるようにする）
        $mappingKeys = [];
        TimestampSongMapping::query()
            ->select(['normalized_text'])
            ->orderBy('normalized_text')
            ->chunk($chunk, function ($rows) use (&$mappingKeys) {
                foreach ($rows as $row) {
                    $mappingKeys[self::toFullWidthKana($row->normalized_text)] = true;
                    // 変換前の値でも引けたかを判定するため元の値も持つ
                    $mappingKeys[$row->normalized_text] = $mappingKeys[$row->normalized_text] ?? true;
                }
            });

        $originalKeys = [];
        TimestampSongMapping::query()
            ->select(['normalized_text'])
            ->orderBy('normalized_text')
            ->chunk($chunk, function ($rows) use (&$originalKeys) {
                foreach ($rows as $row) {
                    $originalKeys[$row->normalized_text] = true;
                }
            });

        $total = 0;
        $changed = 0;
        $newlyLinkable = 0;

        TsItem::query()
            ->select(['id', 'normalized_text'])
            ->whereNotNull('normalized_text')
            ->orderBy('id')
            ->chunkById($chunk, function ($rows) use (&$total, &$changed, &$newlyLinkable, $mappingKeys, $originalKeys) {
                foreach ($rows as $row) {
                    $total++;
                    $new = self::toFullWidthKana($row->normalized_text);

                    if ($new === $row->normalized_text) {
                        continue;
                    }

                    $changed++;

                    // 変換前は引けず、変換後に引けるようになるもの
                    if (! isset($originalKeys[$row->normalized_text]) && isset($mappingKeys[$new])) {
                        $newlyLinkable++;
                    }
                }
            });

        return ['total' => $total, 'changed' => $changed, 'newly_linkable' => $newlyLinkable];
    }

    /**
     * @param  array{total: int, changed: int, groups: array<string, array<int, object>>}  $mappings
     * @param  array{total: int, changed: int, groups: array<string, array<int, object>>}  $decompositions
     * @param  array{total: int, changed: int, newly_linkable: int}  $tsItems
     */
    private function renderSummary(array $mappings, array $decompositions, array $tsItems): void
    {
        $this->line('=== 値が変わる件数 ===');
        $this->table(
            ['テーブル', '全件', '値が変わる', 'UNIQUE衝突するグループ'],
            [
                ['timestamp_song_mappings', $mappings['total'], $mappings['changed'], count($mappings['groups'])],
                ['timestamp_decompositions', $decompositions['total'], $decompositions['changed'], count($decompositions['groups'])],
                ['ts_items (UNIQUE なし)', $tsItems['total'], $tsItems['changed'], '-'],
            ]
        );

        $this->line("変換で新たにマッピングを引けるようになる ts_items: {$tsItems['newly_linkable']}件");
        $this->newLine();
    }

    /**
     * 衝突グループがマージ規則を要するかを判定して出す
     *
     * @param  array{groups: array<string, array<int, object>>}  $mappings
     * @param  array{groups: array<string, array<int, object>>}  $decompositions
     */
    private function renderVerdict(array $mappings, array $decompositions): void
    {
        $mappingConflicts = $this->classifyMappingGroups($mappings['groups']);
        $decompositionConflicts = $this->classifyDecompositionGroups($decompositions['groups']);

        $this->line('=== 衝突グループの内訳 ===');
        $this->table(
            ['テーブル', '衝突グループ', '内容が一致（片方を消せる）', '内容が不一致（判断が必要）'],
            [
                ['timestamp_song_mappings', count($mappings['groups']), $mappingConflicts['same'], $mappingConflicts['different']],
                ['timestamp_decompositions', count($decompositions['groups']), $decompositionConflicts['same'], $decompositionConflicts['different']],
            ]
        );

        $needsDecision = $mappingConflicts['different'] + $decompositionConflicts['different'];

        if (count($mappings['groups']) === 0 && count($decompositions['groups']) === 0) {
            $this->info('衝突はありません。マージ規則なしで再正規化できます。');

            return;
        }

        if ($needsDecision === 0) {
            $this->info('衝突はすべて内容が一致しています。重複行を削除するだけで再正規化できます。');

            return;
        }

        $this->warn("内容が不一致の衝突が {$needsDecision} グループあります。どちらを残すかの規則が必要です。");
        $this->line('--csv= で詳細を出力すると、グループごとの差異を確認できます。');
    }

    /**
     * @param  array<string, array<int, object>>  $groups
     * @return array{same: int, different: int}
     */
    private function classifyMappingGroups(array $groups): array
    {
        $same = 0;
        $different = 0;

        foreach ($groups as $newValue => $rows) {
            $songIds = array_unique(array_map(fn ($r) => $r->song_id, $rows));
            $notSong = array_unique(array_map(fn ($r) => (int) $r->is_not_song, $rows));

            $isSame = count($songIds) === 1 && count($notSong) === 1;
            $isSame ? $same++ : $different++;

            foreach ($rows as $row) {
                $this->csvRows[] = [
                    'table' => 'timestamp_song_mappings',
                    'new_normalized_text' => $newValue,
                    'id' => (string) $row->id,
                    'old_normalized_text' => (string) $row->normalized_text,
                    'detail' => 'song_id='.($row->song_id ?? 'null')
                        .' is_not_song='.(int) $row->is_not_song
                        .' is_manual='.(int) $row->is_manual,
                    'agreement' => $isSame ? 'same' : 'different',
                ];
            }
        }

        return ['same' => $same, 'different' => $different];
    }

    /**
     * @param  array<string, array<int, object>>  $groups
     * @return array{same: int, different: int}
     */
    private function classifyDecompositionGroups(array $groups): array
    {
        $same = 0;
        $different = 0;

        foreach ($groups as $newValue => $rows) {
            $signatures = array_unique(array_map(
                fn ($r) => ($r->song_id ?? '').'|'.$r->status.'|'.($r->derived_title ?? '').'|'.($r->derived_artist ?? ''),
                $rows
            ));

            $isSame = count($signatures) === 1;
            $isSame ? $same++ : $different++;

            foreach ($rows as $row) {
                $this->csvRows[] = [
                    'table' => 'timestamp_decompositions',
                    'new_normalized_text' => $newValue,
                    'id' => (string) $row->id,
                    'old_normalized_text' => (string) $row->normalized_text,
                    'detail' => 'song_id='.($row->song_id ?? 'null')
                        .' status='.$row->status
                        .' title='.($row->derived_title ?? '')
                        .' artist='.($row->derived_artist ?? ''),
                    'agreement' => $isSame ? 'same' : 'different',
                ];
            }
        }

        return ['same' => $same, 'different' => $different];
    }

    private function exportCsv(string $path): void
    {
        $handle = @fopen($path, 'w');

        if ($handle === false) {
            $this->error("CSVを書き込めませんでした: {$path}");

            return;
        }

        fputcsv($handle, ['table', 'new_normalized_text', 'id', 'old_normalized_text', 'detail', 'agreement']);

        foreach ($this->csvRows as $row) {
            fputcsv($handle, array_values($row));
        }

        fclose($handle);

        $count = count($this->csvRows);
        $this->info("CSVを出力しました（{$count}行）: {$path}");
    }
}
