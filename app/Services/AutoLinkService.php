<?php

namespace App\Services;

use App\Models\TimestampSongMapping;
use App\Models\TsItem;
use Illuminate\Support\Facades\Log;

class AutoLinkService
{
    public function __construct(
        protected SongMatchingService $songMatchingService
    ) {}

    /**
     * 未紐付けのタイムスタンプを既存楽曲マスタと照合し、自動紐付けする
     *
     * 楽曲マスタの新規作成は行わない。誤った表記からマスタが量産されるのを
     * 避けるため、自動処理は既存マスタへの紐付けのみに限定する。
     *
     * @param  int  $limit  処理件数上限
     * @param  callable|null  $onProgress  進捗コールバック function(string $message): void
     * @param  string|null  $channelId  チャンネルIDフィルタ
     * @return array{processed: int, linked: int, failed: int, skipped: int}
     */
    public function autoLinkUnlinkedTimestamps(int $limit = 100, ?callable $onProgress = null, ?string $channelId = null): array
    {
        $result = [
            'processed' => 0,
            'linked' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        $unlinkedTexts = $this->getUnlinkedTexts($limit, $channelId);

        if (empty($unlinkedTexts)) {
            $onProgress && $onProgress('未紐付けのタイムスタンプが見つかりませんでした。');

            return $result;
        }

        $total = count($unlinkedTexts);
        $onProgress && $onProgress(sprintf('%d件の未紐付けテキストを処理します。', $total));

        foreach ($unlinkedTexts as $index => $item) {
            $result['processed']++;

            try {
                $match = $this->songMatchingService->findBestMatch($item['normalized_text']);

                if ($match !== null) {
                    $this->createAutoLinkMapping($item['normalized_text'], $match['song_id'], $match['confidence']);

                    $result['linked']++;
                    $onProgress && $onProgress(sprintf(
                        '[%d/%d] 紐付け成功: %s → %s / %s (信頼度 %.2f)',
                        $index + 1,
                        $total,
                        $item['text'],
                        $match['title'],
                        $match['artist'],
                        $match['confidence']
                    ));
                } else {
                    $result['skipped']++;
                    $onProgress && $onProgress(sprintf('[%d/%d] 一致なし: %s', $index + 1, $total, $item['text']));
                }
            } catch (\Exception $e) {
                $result['failed']++;
                $this->log('error', sprintf('自動紐付けエラー: %s - %s', $item['text'], $e->getMessage()));
                $onProgress && $onProgress(sprintf('[%d/%d] エラー: %s - %s', $index + 1, $total, $item['text'], $e->getMessage()));
            }
        }

        return $result;
    }

    /**
     * 未紐付けテキストを照合するが、紐付けは行わずに結果を集計する
     *
     * 閾値を本番へ反映する前に、実データでの的中件数と誤爆の傾向を
     * 確認するために使用する。
     *
     * @param  int  $limit  処理件数上限
     * @param  string|null  $channelId  チャンネルIDフィルタ
     * @return array{
     *     total: int,
     *     auto_linkable: int,
     *     candidate_only: int,
     *     no_match: int,
     *     ambiguous: int,
     *     by_confidence: array<string, int>,
     *     samples: array<int, array{text: string, title: string, artist: string, confidence: float, coverage: float, artist_hit: bool}>,
     *     no_match_samples: string[]
     * }
     */
    public function analyzeUnlinkedTimestamps(int $limit = 100, ?string $channelId = null): array
    {
        $autoThreshold = (float) config('songs.matching.auto_link_threshold', 0.85);
        $candidateThreshold = (float) config('songs.matching.candidate_threshold', 0.5);

        $unlinkedTexts = $this->getUnlinkedTexts($limit, $channelId);

        $summary = [
            'total' => count($unlinkedTexts),
            'auto_linkable' => 0,
            'candidate_only' => 0,
            'no_match' => 0,
            'ambiguous' => 0,
            'by_confidence' => [],
            'samples' => [],
            'no_match_samples' => [],
        ];

        foreach ($unlinkedTexts as $item) {
            $candidates = $this->songMatchingService->findCandidates($item['normalized_text']);

            if (empty($candidates)) {
                $summary['no_match']++;
                if (count($summary['no_match_samples']) < 20) {
                    $summary['no_match_samples'][] = $item['text'];
                }

                continue;
            }

            $best = $candidates[0];
            $confidenceKey = number_format($best['confidence'], 2);
            $summary['by_confidence'][$confidenceKey] = ($summary['by_confidence'][$confidenceKey] ?? 0) + 1;

            // 同信頼度で別楽曲が並ぶ場合は自動紐付けの対象外となる
            $isAmbiguous = isset($candidates[1])
                && $candidates[1]['song_id'] !== $best['song_id']
                && $candidates[1]['confidence'] === $best['confidence'];

            if ($isAmbiguous) {
                $summary['ambiguous']++;
            }

            if ($best['confidence'] >= $autoThreshold && ! $isAmbiguous) {
                $summary['auto_linkable']++;
            } elseif ($best['confidence'] >= $candidateThreshold) {
                $summary['candidate_only']++;
            } else {
                $summary['no_match']++;
            }

            if (count($summary['samples']) < 30) {
                $summary['samples'][] = [
                    'text' => $item['text'],
                    'title' => $best['title'],
                    'artist' => $best['artist'],
                    'confidence' => $best['confidence'],
                    'coverage' => $best['coverage'],
                    'artist_hit' => $best['artist_hit'],
                ];
            }
        }

        krsort($summary['by_confidence']);

        return $summary;
    }

    /**
     * 未紐付けのテキスト一覧を取得
     *
     * @param  int  $limit  取得件数上限
     * @param  string|null  $channelId  チャンネルIDフィルタ
     * @return array<array{text: string, normalized_text: string}>
     */
    protected function getUnlinkedTexts(int $limit, ?string $channelId = null): array
    {
        return TsItem::selectRaw('MIN(ts_items.text) as text, ts_items.normalized_text')
            ->leftJoin('timestamp_song_mappings', 'ts_items.normalized_text', '=', 'timestamp_song_mappings.normalized_text')
            ->whereNotNull('ts_items.text')
            ->where('ts_items.text', '!=', '')
            ->whereNotNull('ts_items.normalized_text')
            ->where('ts_items.is_display', 1)
            ->where('ts_items.type', '!=', '3')
            ->whereHas('archive', function ($q) use ($channelId) {
                $q->where('is_display', 1);
                if ($channelId !== null) {
                    $q->where('channel_id', $channelId);
                }
            })
            ->whereNull('timestamp_song_mappings.id')
            ->groupBy('ts_items.normalized_text')
            ->orderByRaw('MIN(ts_items.text) asc')
            ->limit($limit)
            ->get()
            ->map(fn ($item) => [
                'text' => $item->text,
                'normalized_text' => $item->normalized_text,
            ])
            ->toArray();
    }

    /**
     * 自動紐付けマッピングを作成
     *
     * is_manual を false のまま作成するため、誤った紐付けが混ざった場合でも
     * 自動紐付け分だけをまとめて取り消すことができる。
     */
    protected function createAutoLinkMapping(string $normalizedText, string $songId, float $confidence): void
    {
        TimestampSongMapping::updateOrCreate(
            ['normalized_text' => $normalizedText],
            [
                'song_id' => $songId,
                'is_not_song' => false,
                'status' => TimestampSongMapping::STATUS_LINKED,
                'is_manual' => false,
                'confidence' => $confidence,
            ]
        );
    }

    /**
     * ログ出力
     */
    protected function log(string $level, string $message): void
    {
        Log::log($level, '[AutoLinkService] '.$message);
    }
}
