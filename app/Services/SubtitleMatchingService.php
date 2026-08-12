<?php

namespace App\Services;

use App\Models\Archive;
use App\Models\SubtitleFingerprint;
use App\Models\TsItem;
use Illuminate\Support\Collection;

class SubtitleMatchingService
{
    private const MIN_CHANNEL_CANDIDATES = 3;

    public function __construct(private SubtitleFingerprintService $fingerprintService) {}

    /**
     * 候補楽曲を返す（チャンネル内優先→全体フォールバック）
     */
    public function getCandidateSongs(string $tsItemId, float $threshold = 0.5): array
    {
        $fingerprint = SubtitleFingerprint::where('ts_item_id', $tsItemId)->first();

        if (! $fingerprint) {
            return [
                'ts_item_id' => $tsItemId,
                'has_fingerprint' => false,
                'candidates' => [],
            ];
        }

        return [
            'ts_item_id' => $tsItemId,
            'has_fingerprint' => true,
            'candidates' => $this->findCandidates(
                $fingerprint->trigrams,
                $fingerprint->duration_sec,
                $fingerprint->video_id,
                $fingerprint->ts_item_id,
                $threshold
            ),
        ];
    }

    /**
     * 再生位置から候補楽曲を返す（拡張のマーカー用。ts_item不要）
     *
     * 保存済み字幕から指定位置の窓を切り出し、保存済みフィンガープリントと照合する
     */
    public function getCandidateSongsForPosition(string $videoId, int $sec, float $threshold = 0.5): array
    {
        $subtitle = $this->fingerprintService->findPreferredSubtitle($videoId);

        if (! $subtitle) {
            return [
                'has_subtitles' => false,
                'has_fingerprint' => false,
                'candidates' => [],
            ];
        }

        $text = $this->fingerprintService->extractSubtitleWindow($subtitle->subtitle_data ?? [], $sec);
        $trigrams = SubtitleFingerprintService::generateTrigrams($text);

        if (count($trigrams) < SubtitleFingerprintService::MIN_TRIGRAM_COUNT) {
            return [
                'has_subtitles' => true,
                'has_fingerprint' => false,
                'candidates' => [],
            ];
        }

        return [
            'has_subtitles' => true,
            'has_fingerprint' => true,
            'candidates' => $this->findCandidates(
                $trigrams,
                SubtitleFingerprintService::WINDOW_DURATION_SEC,
                $videoId,
                null,
                $threshold
            ),
        ];
    }

    /**
     * トライグラム集合に対する候補検索の共通処理
     *
     * @param  string|null  $excludeTsItemId  照合対象から除外するts_item（自分自身との照合を防ぐ）
     */
    private function findCandidates(
        array $trigrams,
        int $durationSec,
        string $videoId,
        ?string $excludeTsItemId,
        float $threshold
    ): array {
        $channelId = Archive::where('video_id', $videoId)->first()?->channel_id;

        // 同一チャンネル内で検索
        $channelCandidates = collect();
        $channelMatchedIds = collect();
        if ($channelId) {
            $channelCandidates = $this->findSimilarInChannel($trigrams, $durationSec, $excludeTsItemId, $channelId, $threshold);
            $channelMatchedIds = $channelCandidates->pluck('fingerprint.ts_item_id');
        }

        // 候補が少なければ全チャンネルにフォールバック（チャンネル内の結果は除外）
        $otherCandidates = collect();
        if ($channelCandidates->count() < self::MIN_CHANNEL_CANDIDATES) {
            $otherCandidates = $this->findSimilarOtherChannels($trigrams, $durationSec, $excludeTsItemId, $channelMatchedIds->toArray(), $threshold);
        }

        return $this->rankCandidates($channelCandidates, $otherCandidates);
    }

    /**
     * トライグラムJaccard類似度
     */
    public static function jaccardSimilarity(array $trigramsA, array $trigramsB): float
    {
        if (empty($trigramsA) || empty($trigramsB)) {
            return 0.0;
        }

        $setA = array_flip($trigramsA);
        $setB = array_flip($trigramsB);

        $intersectionCount = count(array_intersect_key($setA, $setB));
        $unionCount = count($setA) + count($setB) - $intersectionCount;

        if ($unionCount === 0) {
            return 0.0;
        }

        return $intersectionCount / $unionCount;
    }

    /**
     * 同一チャンネル内で類似フィンガープリントを検索
     *
     * 窓の長さ（duration_sec）が異なるものは比較しない。短い窓のトライグラム集合は
     * 長い窓のほぼ部分集合になるため、同一楽曲でもJaccard類似度が構造的に低く出て
     * しきい値を下回る。窓の長さを変更した直後の混在状態で静かに取りこぼすのを防ぐ。
     */
    private function findSimilarInChannel(array $targetTrigrams, int $durationSec, ?string $excludeTsItemId, string $channelId, float $threshold): Collection
    {
        $videoIds = Archive::where('channel_id', $channelId)
            ->pluck('video_id')
            ->toArray();

        $results = collect();

        SubtitleFingerprint::whereIn('video_id', $videoIds)
            ->when($excludeTsItemId, fn ($q) => $q->where('ts_item_id', '!=', $excludeTsItemId))
            ->where('duration_sec', $durationSec)
            ->chunkById(500, function ($chunk) use ($targetTrigrams, $threshold, &$results) {
                foreach ($chunk as $other) {
                    $similarity = self::jaccardSimilarity($targetTrigrams, $other->trigrams);
                    if ($similarity >= $threshold) {
                        $results->push([
                            'fingerprint' => $other,
                            'similarity' => round($similarity, 4),
                            'source' => 'same_channel',
                        ]);
                    }
                }
            });

        return $results->sortByDesc('similarity');
    }

    /**
     * 他チャンネルで検索（フォールバック、チャンネル内の結果は除外）
     */
    private function findSimilarOtherChannels(array $targetTrigrams, int $durationSec, ?string $excludeTsItemId, array $excludeTsItemIds, float $threshold): Collection
    {
        $results = collect();

        $query = SubtitleFingerprint::query()
            ->when($excludeTsItemId, fn ($q) => $q->where('ts_item_id', '!=', $excludeTsItemId))
            ->where('duration_sec', $durationSec);
        if (! empty($excludeTsItemIds)) {
            $query->whereNotIn('ts_item_id', $excludeTsItemIds);
        }

        $query->chunkById(500, function ($chunk) use ($targetTrigrams, $threshold, &$results) {
            foreach ($chunk as $other) {
                $similarity = self::jaccardSimilarity($targetTrigrams, $other->trigrams);
                if ($similarity >= $threshold) {
                    $results->push([
                        'fingerprint' => $other,
                        'similarity' => round($similarity, 4),
                        'source' => 'other_channel',
                    ]);
                }
            }
        });

        return $results->sortByDesc('similarity');
    }

    /**
     * 候補をsong単位でグループ化・ランク付け
     */
    private function rankCandidates(Collection $channelMatches, Collection $otherMatches): array
    {
        // 全マッチを統合（重複はチャンネル内を優先）
        $seenTsItemIds = [];
        $allResults = collect();

        foreach ($channelMatches as $match) {
            $tsItemId = $match['fingerprint']->ts_item_id;
            $seenTsItemIds[$tsItemId] = true;
            $allResults->push($match);
        }

        foreach ($otherMatches as $match) {
            $tsItemId = $match['fingerprint']->ts_item_id;
            if (! isset($seenTsItemIds[$tsItemId])) {
                $seenTsItemIds[$tsItemId] = true;
                $allResults->push($match);
            }
        }

        // ts_item情報をまとめて取得
        $tsItemIds = $allResults->pluck('fingerprint.ts_item_id')->toArray();
        $tsItems = TsItem::whereIn('id', $tsItemIds)->get()->keyBy('id');

        // マッピング情報を取得（normalized_text → song）
        $normalizedTexts = $tsItems->pluck('normalized_text')->unique()->filter()->toArray();
        $mappings = \App\Models\TimestampSongMapping::whereIn('normalized_text', $normalizedTexts)
            ->where('is_not_song', false)
            ->whereNotNull('song_id')
            ->with('song')
            ->get()
            ->keyBy('normalized_text');

        // song_id（またはnormalized_text）でグループ化
        $groups = [];
        foreach ($allResults as $match) {
            $tsItem = $tsItems->get($match['fingerprint']->ts_item_id);
            if (! $tsItem) {
                continue;
            }

            $normalizedText = $tsItem->normalized_text;
            $mapping = $mappings->get($normalizedText);
            $song = $mapping?->song;

            // グループキー: song_idがあればそれ、なければnormalized_text
            $groupKey = $song ? 'song:'.$song->id : 'text:'.$normalizedText;

            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'song_id' => $song?->id,
                    'song_title' => $song?->title,
                    'song_artist' => $song?->artist,
                    'normalized_text' => $normalizedText,
                    'max_similarity' => 0,
                    'total_similarity' => 0,
                    'matches' => [],
                ];
            }

            $groups[$groupKey]['matches'][] = [
                'ts_item_id' => $tsItem->id,
                'video_id' => $match['fingerprint']->video_id,
                'text' => $tsItem->text,
                'similarity' => $match['similarity'],
                'source' => $match['source'],
            ];

            $groups[$groupKey]['max_similarity'] = max(
                $groups[$groupKey]['max_similarity'],
                $match['similarity']
            );
            $groups[$groupKey]['total_similarity'] += $match['similarity'];
        }

        // ランク付け: 類似度平均 × マッチ数でスコア算出
        $ranked = collect($groups)->map(function ($group) {
            $matchCount = count($group['matches']);
            $avgSimilarity = $matchCount > 0 ? $group['total_similarity'] / $matchCount : 0;

            // 同一チャンネル内のマッチを優先
            $sameChannelCount = collect($group['matches'])->where('source', 'same_channel')->count();

            return [
                'song_id' => $group['song_id'],
                'song_title' => $group['song_title'],
                'song_artist' => $group['song_artist'],
                'normalized_text' => $group['normalized_text'],
                'similarity' => round($group['max_similarity'], 4),
                'match_count' => $matchCount,
                'source' => $sameChannelCount > 0 ? 'same_channel' : 'other_channel',
                'matches' => array_slice($group['matches'], 0, 10),
            ];
        })
            ->sortByDesc('similarity')
            ->values()
            ->toArray();

        return $ranked;
    }
}
