<?php

namespace App\Console\Commands;

use App\Helpers\SupplementStripper;
use App\Helpers\TextNormalizer;
use App\Models\TimestampDecomposition;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanDecompositionSupplements extends Command
{
    protected $signature = 'ts-decompositions:clean-supplements
        {--apply : 実際に更新する（指定しない場合はドライラン）}
        {--rules=symbol,bracket,trailing : 適用するルール（symbol/bracket/trailing をカンマ区切り）}
        {--status= : 対象を絞り込むステータス（pending/selected/skipped/auto_matched）}
        {--chunk=500 : 読み込みチャンクサイズ}
        {--limit=30 : 表示するサンプル件数}
        {--csv= : 全結果をCSVに書き出すパス}';

    protected $description = 'TS分解のパーツ・確定名から「曲名ではない補足」を除去する（既定はドライラン）';

    public function handle(): int
    {
        $rules = $this->resolveRules();

        if ($rules === null) {
            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');

        $this->line('モード: '.($apply ? '<comment>APPLY（更新します）</comment>' : '<info>ドライラン（更新しません）</info>'));
        $this->line('適用ルール: <info>'.implode(', ', $rules).'</info>');
        $this->line('キーワード辞書: <info>'.count(config('supplement_strip.keywords', [])).'件</info>'
            .' / 装飾記号: <info>'.count(config('supplement_strip.symbols', [])).'件</info>');
        $this->newLine();

        $changes = $this->collectChanges($rules);

        if ($changes['scanned'] === 0) {
            $this->warn('対象のTS分解レコードがありませんでした。');

            return self::SUCCESS;
        }

        $this->renderSummary($changes);
        $this->renderKeywordHits($changes['rows']);
        $this->renderSamples($changes['rows']);
        $this->renderArtistMerges($changes['rows']);

        if ($csvPath = $this->option('csv')) {
            $this->exportCsv($changes['rows'], (string) $csvPath);
        }

        if ($apply) {
            $updated = $this->applyChanges($changes['rows']);
            $this->newLine();
            $this->info("更新しました: {$updated}件");
        } else {
            $this->newLine();
            $this->info('※ ドライランです。データは変更していません。適用するには --apply を付けてください。');
        }

        return self::SUCCESS;
    }

    /**
     * --rules を検証して配列で返す（不正な場合は null）
     *
     * @return string[]|null
     */
    private function resolveRules(): ?array
    {
        $validated = SupplementStripper::validateRules(explode(',', (string) $this->option('rules')));

        if (! empty($validated['invalid'])) {
            $this->error('不明なルール: '.implode(', ', $validated['invalid']));
            $this->line('指定できるルール: '.implode(', ', SupplementStripper::ALL_RULES));

            return null;
        }

        if (empty($validated['rules'])) {
            $this->error('ルールが1つも指定されていません。');

            return null;
        }

        return $validated['rules'];
    }

    /**
     * 全レコードを走査し、変化するものだけを集める
     *
     * @param  string[]  $rules
     * @return array{scanned: int, rows: array<int, array<string, mixed>>}
     */
    private function collectChanges(array $rules): array
    {
        $query = TimestampDecomposition::query();

        if ($status = $this->option('status')) {
            $query->where('status', $status);
        }

        $scanned = (clone $query)->count();

        if ($scanned === 0) {
            return ['scanned' => 0, 'rows' => []];
        }

        $this->line("走査対象: <info>{$scanned}件</info>のTS分解レコード");
        $bar = $this->output->createProgressBar($scanned);
        $bar->start();

        $rows = [];

        $query->orderBy('id')->chunk((int) $this->option('chunk'), function ($decompositions) use (&$rows, $rules, $bar) {
            foreach ($decompositions as $decomposition) {
                $bar->advance();

                $change = $this->buildChange($decomposition, $rules);

                if ($change !== null) {
                    $rows[] = $change;
                }
            }
        });

        $bar->finish();
        $this->newLine(2);

        return ['scanned' => $scanned, 'rows' => $rows];
    }

    /**
     * 1レコード分の変更内容を組み立てる（変化が無ければ null）
     *
     * @param  string[]  $rules
     * @return array<string, mixed>|null
     */
    private function buildChange(TimestampDecomposition $decomposition, array $rules): ?array
    {
        $hits = [];

        // パーツは要素数を変えない（title_part_index / artist_part_index がズレるため）
        $originalParts = $decomposition->parts ?? [];
        $cleanedParts = [];

        foreach (SupplementStripper::analyzeParts($originalParts, $rules) as $analysis) {
            $cleanedParts[] = $analysis['result'];

            foreach ($analysis['hits'] as $hit) {
                $hits[] = $hit;
            }
        }

        // derived_title / derived_artist はパーツから選ばれた値なので、
        // 区切り必須ガードの扱いもパーツと揃える
        $requireSeparator = SupplementStripper::requiresSeparatorForParts($originalParts);

        // derived_* のヒットは集計に含めない。パーツから選ばれた値なので、
        // 同じ補足がパーツ側と derived_title / derived_artist で最大3回数えられ、
        // 辞書調整の判断材料（ヒット数）が実際の出現数の2〜3倍に膨らむ。
        $derivedHits = [];
        $cleanedTitle = $this->cleanDerived($decomposition->derived_title, $rules, $derivedHits, $requireSeparator);
        $cleanedArtist = $this->cleanDerived($decomposition->derived_artist, $rules, $derivedHits, $requireSeparator);

        $partsChanged = $cleanedParts !== $originalParts;
        $titleChanged = $cleanedTitle !== $decomposition->derived_title;
        $artistChanged = $cleanedArtist !== $decomposition->derived_artist;

        if (! $partsChanged && ! $titleChanged && ! $artistChanged) {
            return null;
        }

        $hitLabels = [];

        foreach ($hits as $hit) {
            $label = $hit['rule'].($hit['keyword'] !== null ? ':'.$hit['keyword'] : '');
            $hitLabels[$label] = ($hitLabels[$label] ?? 0) + 1;
        }

        arsort($hitLabels);

        return [
            'id' => $decomposition->id,
            'original_text' => $decomposition->original_text,
            'status' => $decomposition->status,
            'old_parts' => $originalParts,
            'new_parts' => $cleanedParts,
            'old_title' => $decomposition->derived_title,
            'new_title' => $cleanedTitle,
            'old_artist' => $decomposition->derived_artist,
            'new_artist' => $cleanedArtist,
            'parts_changed' => $partsChanged,
            'title_changed' => $titleChanged,
            'artist_changed' => $artistChanged,
            'hits' => $hitLabels,
        ];
    }

    /**
     * derived_title / derived_artist をクリーニングする
     *
     * @param  string[]  $rules
     * @param  array<int, array{rule: string, keyword: ?string, removed: string}>  $hits
     * @param  bool  $requireSeparator  区切り以降ルールを区切りを含む値に限定するか
     */
    private function cleanDerived(?string $value, array $rules, array &$hits, bool $requireSeparator = false): ?string
    {
        if ($value === null || trim($value) === '') {
            return $value;
        }

        $analysis = SupplementStripper::analyze($value, $rules, ['require_separator' => $requireSeparator]);

        foreach ($analysis['hits'] as $hit) {
            $hits[] = $hit;
        }

        return $analysis['result'];
    }

    /**
     * @param  array{scanned: int, rows: array<int, array<string, mixed>>}  $changes
     */
    private function renderSummary(array $changes): void
    {
        $rows = $changes['rows'];

        $partsChanged = count(array_filter($rows, fn ($row) => $row['parts_changed']));
        $titleChanged = count(array_filter($rows, fn ($row) => $row['title_changed']));
        $artistChanged = count(array_filter($rows, fn ($row) => $row['artist_changed']));

        $this->line('<comment>=== サマリー ===</comment>');
        $this->table(
            ['項目', '件数'],
            [
                ['走査したレコード', $changes['scanned']],
                ['<info>変化するレコード</info>', count($rows)],
                ['　うち パーツが変化', $partsChanged],
                ['　うち 確定した曲名が変化', $titleChanged],
                ['　うち 確定したアーティストが変化', $artistChanged],
            ]
        );

        if ($changes['scanned'] > 0) {
            $rate = round(count($rows) / $changes['scanned'] * 100, 1);
            $this->line("変化率: <info>{$rate}%</info>");
        }

        $this->newLine();
    }

    /**
     * どのルール・キーワードが効いたかを集計（辞書調整の材料）
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function renderKeywordHits(array $rows): void
    {
        $this->line('<comment>=== ヒットしたルール・キーワード ===</comment>');

        $hits = [];

        foreach ($rows as $row) {
            foreach ($row['hits'] as $label => $count) {
                $hits[$label] = ($hits[$label] ?? 0) + $count;
            }
        }

        if ($hits === []) {
            $this->line('ヒットなし');
            $this->newLine();

            return;
        }

        arsort($hits);

        $table = [];

        foreach ($hits as $label => $count) {
            [$rule, $keyword] = array_pad(explode(':', $label, 2), 2, '-');
            $table[] = [$rule, $keyword, $count];
        }

        $this->line('<comment>ヒット0件のキーワードは辞書から外し、多すぎるものは誤爆を確認する</comment>');
        $this->table(['ルール', 'キーワード', 'ヒット数'], $table);

        $unused = array_values(array_diff(
            config('supplement_strip.keywords', []),
            array_map(fn ($label) => explode(':', $label, 2)[1] ?? '', array_keys($hits))
        ));

        if ($unused !== []) {
            $this->line('一度もヒットしなかったキーワード: <comment>'.implode(', ', $unused).'</comment>');
        }

        $this->newLine();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function renderSamples(array $rows): void
    {
        $this->line('<comment>=== 変換サンプル ===</comment>');

        if ($rows === []) {
            $this->line('該当なし');
            $this->newLine();

            return;
        }

        $limit = (int) $this->option('limit');
        $table = [];

        foreach (array_slice($rows, 0, $limit) as $row) {
            $table[] = [
                $row['status'],
                $this->truncate($row['original_text'] ?? '', 34),
                $this->truncate(implode(' | ', $row['old_parts']), 34),
                $this->truncate(implode(' | ', $row['new_parts']), 34),
                $this->truncate(implode(' ', array_keys($row['hits'])), 24),
            ];
        }

        $this->table(['状態', '元テキスト', '変更前パーツ', '変更後パーツ', 'ヒット'], $table);

        $shown = min(count($rows), $limit);
        $this->line('表示: '.$shown.'件 / 該当 '.count($rows).'件'
            .(count($rows) > $shown ? '（--limit で増やせます。全件は --csv で出力）' : ''));
        $this->newLine();
    }

    /**
     * クリーニングによってアーティスト名が既存の表記に揃う件数を出す
     *
     * 揃った分だけ cascadeArtistSelection がまとめて効くようになる
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function renderArtistMerges(array $rows): void
    {
        $changed = array_filter($rows, fn ($row) => $row['artist_changed'] && $row['new_artist'] !== null);

        if ($changed === []) {
            return;
        }

        // 既に綺麗な状態で存在するアーティスト表記
        //
        // 変更対象の行自身は除外する。含めると、空白差だけで artist_changed に
        // なった行が自分自身にマッチし、揃う相手が居ないのに「揃う件数」が
        // 立ってしまう（normalize() が空白差を吸収するため）。
        $existing = TimestampDecomposition::whereNotNull('derived_artist')
            ->whereNotIn('id', array_column($changed, 'id'))
            ->pluck('derived_artist')
            ->map(fn ($artist) => TextNormalizer::normalize($artist))
            ->filter()
            ->flip();

        $merges = [];

        foreach ($changed as $row) {
            $normalized = TextNormalizer::normalize($row['new_artist']);

            if ($normalized !== '' && $existing->has($normalized)) {
                $merges[$row['new_artist']] = ($merges[$row['new_artist']] ?? 0) + 1;
            }
        }

        if ($merges === []) {
            return;
        }

        arsort($merges);

        $this->line('<comment>=== 既存のアーティスト表記に揃うもの ===</comment>');
        $this->line('揃った分だけアーティストのカスケード選別がまとめて効くようになります');

        $table = [];

        foreach (array_slice($merges, 0, (int) $this->option('limit'), true) as $artist => $count) {
            $table[] = [$this->truncate($artist, 40), $count];
        }

        $this->table(['アーティスト', '揃う件数'], $table);
        $this->newLine();
    }

    /**
     * 変更を実際に書き込む
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function applyChanges(array $rows): int
    {
        $updated = 0;

        DB::transaction(function () use ($rows, &$updated) {
            foreach ($rows as $row) {
                // updated_at / updated_by は意図的に更新しない。
                // TimestampDecompositionService::undoAction() は
                // 「同じ updated_by かつ updated_at が近いもの」をカスケード操作の
                // まとまりとみなして一緒に戻すため、表記の掃除でこれらを書き換えると
                // 無関係なレコードが巻き込まれて戻されてしまう。
                // 掃除の記録は --csv で残す。
                DB::table('timestamp_decompositions')
                    ->where('id', $row['id'])
                    ->update([
                        'parts' => json_encode($row['new_parts'], JSON_UNESCAPED_UNICODE),
                        'derived_title' => $row['new_title'],
                        'derived_artist' => $row['new_artist'],
                    ]);
                $updated++;
            }
        });

        return $updated;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function exportCsv(array $rows, string $path): void
    {
        $handle = @fopen($path, 'w');

        if ($handle === false) {
            $this->error("CSVを書き出せませんでした: {$path}");

            return;
        }

        // Excel で開いたときに文字化けしないよう BOM を付ける
        fwrite($handle, "\u{FEFF}");
        fputcsv($handle, [
            'id', '状態', '元テキスト',
            '変更前パーツ', '変更後パーツ',
            '変更前の曲名', '変更後の曲名',
            '変更前のアーティスト', '変更後のアーティスト',
            'ヒット',
        ]);

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['id'],
                $row['status'],
                $row['original_text'],
                implode(' | ', $row['old_parts']),
                implode(' | ', $row['new_parts']),
                $row['old_title'],
                $row['new_title'],
                $row['old_artist'],
                $row['new_artist'],
                implode(' ', array_keys($row['hits'])),
            ]);
        }

        fclose($handle);

        $this->info("CSVを書き出しました: {$path}");
    }

    private function truncate(string $text, int $length = 40): string
    {
        return mb_strlen($text, 'UTF-8') > $length
            ? mb_substr($text, 0, $length, 'UTF-8').'…'
            : $text;
    }
}
