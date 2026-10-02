<?php

namespace App\Services;

use App\Models\Song;
use App\Models\SongAudit;
use App\Models\TimestampSongMapping;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * 楽曲マスタ・紐付けの点検（Claude Code 等による判定）の入出力
 *
 * ここで書き込むのは song_audits のみ。songs やマッピングの実変更は行わない。
 */
class SongAuditService
{
    private const TEXT_EXAMPLE_LIMIT = 3;

    private const CHUNK_SIZE = 200;

    /**
     * 未判定、または判定後に内容が変わった対象を最大 $limit 件返す
     */
    public function export(string $type, int $limit): array
    {
        $results = [];

        $this->targetQuery($type)->chunkById(self::CHUNK_SIZE, function (Collection $targets) use ($type, $limit, &$results) {
            $judged = SongAudit::where('target_type', $type)
                ->whereIn('target_id', $targets->pluck('id'))
                ->pluck('fingerprint', 'target_id');

            $pending = $targets->filter(function ($target) use ($type, $judged) {
                return ($judged[$target->id] ?? null) !== $this->fingerprint($type, $target);
            })->take($limit - count($results));

            if ($pending->isEmpty()) {
                return true;
            }

            $examples = $this->textExamples($type, $pending);
            foreach ($pending as $target) {
                $results[] = $this->present($type, $target, $examples);
            }

            return count($results) < $limit;
        });

        return $results;
    }

    /**
     * 判定結果を検証して登録する
     *
     * 1件ずつ検証し、不正な行・export 後に対象が変わった行は登録しない。
     *
     * @return array{registered: int, stale: array<int, string>, errors: array<int, string>}
     */
    public function import(array $items, string $judgedBy, bool $dryRun = false): array
    {
        $summary = ['registered' => 0, 'stale' => [], 'errors' => []];

        foreach (array_values($items) as $index => $item) {
            $label = '#'.($index + 1);

            $error = $this->validateItem($item);
            if ($error !== null) {
                $summary['errors'][] = "{$label}: {$error}";

                continue;
            }

            // export の対象条件では絞らない。紐付けが外れた行は「対象なし」ではなく更新扱いにする
            $target = $item['type'] === SongAudit::TARGET_SONG
                ? Song::with('tags')->find($item['id'])
                : TimestampSongMapping::with('song')->find($item['id']);
            if ($target === null) {
                $summary['errors'][] = "{$label}: 対象が存在しません ({$item['type']} {$item['id']})";

                continue;
            }

            if ($this->fingerprint($item['type'], $target) !== $item['fingerprint']) {
                $summary['stale'][] = "{$label}: export 後に対象が更新されています ({$item['type']} {$item['id']})";

                continue;
            }

            if (! $dryRun) {
                SongAudit::updateOrCreate(
                    ['target_type' => $item['type'], 'target_id' => $item['id']],
                    [
                        'fingerprint' => $item['fingerprint'],
                        'verdict' => $item['verdict'],
                        'reason' => $item['reason'] ?? null,
                        'suggestion' => $item['suggestion'] ?? null,
                        'judged_by' => $judgedBy,
                        'judged_at' => now(),
                        'resolution' => SongAudit::RESOLUTION_PENDING,
                    ]
                );
            }
            $summary['registered']++;
        }

        return $summary;
    }

    /**
     * 判定の根拠になった内容のハッシュ
     *
     * export で見せている内容（タイムスタンプの例を除く）が変われば値が変わる。
     */
    public function fingerprint(string $type, Song|TimestampSongMapping $target): string
    {
        if ($type === SongAudit::TARGET_SONG) {
            $content = [
                $target->title,
                $target->artist,
                $target->tags->pluck('value')->sort()->values()->all(),
            ];
        } else {
            $content = [
                $target->normalized_text,
                $target->song_id,
                $target->song?->title,
                $target->song?->artist,
            ];
        }

        return sha1(json_encode($content, JSON_UNESCAPED_UNICODE));
    }

    private function targetQuery(string $type)
    {
        if ($type === SongAudit::TARGET_SONG) {
            return Song::with('tags');
        }

        // 誤った紐付けを探すのが目的なので、楽曲に紐付いているものだけを対象にする
        return TimestampSongMapping::with('song')
            ->whereNotNull('song_id')
            ->where('is_not_song', false);
    }

    /**
     * 対象ごとのタイムスタンプ元テキストの例（normalized_text 単位で重複除去）
     *
     * @return array<string, array<int, string>> 対象ID => 元テキストの例
     */
    private function textExamples(string $type, Collection $targets): array
    {
        if ($type === SongAudit::TARGET_SONG) {
            $textsByTarget = TimestampSongMapping::whereIn('song_id', $targets->pluck('id'))
                ->orderBy('normalized_text')
                ->get(['song_id', 'normalized_text'])
                ->groupBy('song_id')
                ->map(fn ($mappings) => $mappings->pluck('normalized_text')->take(self::TEXT_EXAMPLE_LIMIT)->all());
        } else {
            $textsByTarget = $targets->mapWithKeys(fn ($mapping) => [$mapping->id => [$mapping->normalized_text]]);
        }

        $normalizedTexts = $textsByTarget->flatten()->unique()->values();
        $originals = DB::table('ts_items')
            ->whereIn('normalized_text', $normalizedTexts)
            ->orderBy('text')
            ->get(['normalized_text', 'text'])
            ->groupBy('normalized_text')
            ->map(fn ($rows) => $rows->pluck('text')->unique()->values());

        $examples = [];
        foreach ($textsByTarget as $targetId => $texts) {
            $list = collect($texts)->flatMap(fn ($text) => $originals[$text] ?? collect([$text]));
            $examples[$targetId] = $list->unique()->take(self::TEXT_EXAMPLE_LIMIT)->values()->all();
        }

        return $examples;
    }

    private function present(string $type, Song|TimestampSongMapping $target, array $examples): array
    {
        if ($type === SongAudit::TARGET_SONG) {
            return [
                'type' => $type,
                'id' => $target->id,
                'fingerprint' => $this->fingerprint($type, $target),
                'title' => $target->title,
                'artist' => $target->artist,
                'tags' => $target->tags->pluck('value')->sort()->values()->all(),
                'timestamp_examples' => $examples[$target->id] ?? [],
            ];
        }

        return [
            'type' => $type,
            'id' => $target->id,
            'fingerprint' => $this->fingerprint($type, $target),
            'normalized_text' => $target->normalized_text,
            'original_texts' => $examples[$target->id] ?? [],
            'song' => [
                'id' => $target->song->id,
                'title' => $target->song->title,
                'artist' => $target->song->artist,
            ],
        ];
    }

    /**
     * @return string|null エラーメッセージ（問題なければ null）
     */
    private function validateItem(mixed $item): ?string
    {
        if (! is_array($item)) {
            return 'オブジェクトではありません';
        }

        $validator = Validator::make($item, [
            'type' => ['required', 'string', 'in:'.SongAudit::TARGET_SONG.','.SongAudit::TARGET_MAPPING],
            'id' => ['required', 'string', 'max:26'],
            'fingerprint' => ['required', 'string', 'size:40'],
            'verdict' => ['required', 'string', 'in:'.SongAudit::VERDICT_OK.','.SongAudit::VERDICT_NEEDS_FIX],
            'reason' => ['nullable', 'string', 'max:1000', 'required_if:verdict,'.SongAudit::VERDICT_NEEDS_FIX],
            'suggestion' => ['nullable', 'array'],
            'suggestion.title' => ['nullable', 'string', 'max:255'],
            'suggestion.artist' => ['nullable', 'string', 'max:255'],
            'suggestion.is_not_song' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return implode(' / ', $validator->errors()->all());
        }

        $allowedKeys = ['title', 'artist', 'is_not_song'];
        $unknownKeys = array_diff(array_keys($item['suggestion'] ?? []), $allowedKeys);
        if (! empty($unknownKeys)) {
            return 'suggestion に未知のキーがあります: '.implode(', ', $unknownKeys);
        }

        if (($item['suggestion']['is_not_song'] ?? false) && $item['type'] !== SongAudit::TARGET_MAPPING) {
            return 'is_not_song は紐付けの判定でのみ指定できます';
        }

        return null;
    }
}
