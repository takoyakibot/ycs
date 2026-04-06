<?php

namespace App\Services;

use App\Models\Archive;
use App\Models\SubtitleFingerprint;
use App\Models\TsItem;
use Illuminate\Support\Collection;

class SubtitleMatchingService
{
    private const MIN_CHANNEL_CANDIDATES = 3;

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

        // チャンネルIDを取得
        $archive = Archive::where('video_id', $fingerprint->video_id)->first();
        $channelId = $archive?->channel_id;

        // 同一チャンネル内で検索
        $channelCandidates = collect();
        $channelMatchedIds = collect();
        if ($channelId) {
            $channelCandidates = $this->findSimilarInChannel($fingerprint, $channelId, $threshold);
            $channelMatchedIds = $channelCandidates->pluck('fingerprint.ts_item_id');
        }

        // 候補が少なければ全チャンネルにフォールバック（チャンネル内の結果は除外）
        $otherCandidates = collect();
        if ($channelCandidates->count() < self::MIN_CHANNEL_CANDIDATES) {
            $otherCandidates = $this->findSimilarOtherChannels($fingerprint, $channelMatchedIds->toArray(), $threshold);
        }

        // 結果を統合してランク付け
        return [
            'ts_item_id' => $tsItemId,
            'has_fingerprint' => true,
            'candidates' => $this->rankCandidates($channelCandidates, $otherCandidates),
        ];
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
     */
    private function findSimilarInChannel(SubtitleFingerprint $fp, string $channelId, float $threshold): Collection
    {
        // 同一チャンネルの動画IDを取得
        $videoIds = Archive::where('channel_id', $channelId)
            ->pluck('video_id')
            ->toArray();

        $others = SubtitleFingerprint::whereIn('video_id', $videoIds)
            ->where('ts_item_id', '!=', $fp->ts_item_id)
            ->get();

        return $this->filterBySimilarity($fp, $others, $threshold, 'same_channel');
    }

    /**
     * 他チャンネルで検索（フォールバック、チャンネル内の結果は除外）
     */
    private function findSimilarOtherChannels(SubtitleFingerprint $fp, array $excludeTsItemIds, float $threshold): Collection
    {
        $targetTrigrams = $fp->trigrams;
        $results = collect();

        $query = SubtitleFingerprint::where('ts_item_id', '!=', $fp->ts_item_id);
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
     * 類似度でフィルタリング
     */
    private function filterBySimilarity(SubtitleFingerprint $fp, Collection $others, float $threshold, string $source): Collection
    {
        $targetTrigrams = $fp->trigrams;
        $results = collect();

        foreach ($others as $other) {
            $similarity = self::jaccardSimilarity($targetTrigrams, $other->trigrams);
            if ($similarity >= $threshold) {
                $results->push([
                    'fingerprint' => $other,
                    'similarity' => round($similarity, 4),
                    'source' => $source,
                ]);
            }
        }

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
