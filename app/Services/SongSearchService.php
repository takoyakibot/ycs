<?php

namespace App\Services;

use App\Helpers\TextNormalizer;
use App\Models\Song;

class SongSearchService
{
    protected SimilarityService $similarityService;

    public function __construct(SimilarityService $similarityService)
    {
        $this->similarityService = $similarityService;
    }

    /**
     * 類似する楽曲を検索
     *
     * @param  string  $normalizedTitle  正規化済みタイトル
     * @param  string  $normalizedArtist  正規化済みアーティスト名
     * @param  float  $threshold  類似度の閾値（デフォルト: 0.75）
     * @return array 類似楽曲の配列
     */
    public function findSimilarSongs(string $normalizedTitle, string $normalizedArtist, float $threshold = 0.75): array
    {
        // パフォーマンス最適化：部分一致で候補を絞り込んでから類似度計算
        // 正規化後のタイトル・アーティストの最初の3文字で絞り込み
        $titlePrefix = mb_substr($normalizedTitle, 0, 3);
        $artistPrefix = mb_substr($normalizedArtist, 0, 3);

        $candidateSongs = Song::where(function ($query) use ($titlePrefix, $artistPrefix) {
            $query->where('title', 'like', "{$titlePrefix}%")
                ->orWhere('artist', 'like', "{$artistPrefix}%");
        })->limit(100)->get();

        $similarSongs = [];

        foreach ($candidateSongs as $song) {
            $songNormalizedTitle = TextNormalizer::normalize($song->title);
            $songNormalizedArtist = TextNormalizer::normalize($song->artist);

            // タイトルとアーティスト名の類似度を計算
            $titleSimilarity = $this->similarityService->calculateSimilarity($normalizedTitle, $songNormalizedTitle);
            $artistSimilarity = $this->similarityService->calculateSimilarity($normalizedArtist, $songNormalizedArtist);

            // 両方の平均が閾値以上の場合に類似とみなす
            $averageSimilarity = ($titleSimilarity + $artistSimilarity) / 2;

            if ($averageSimilarity >= $threshold) {
                $similarSongs[] = [
                    'song' => $song,
                    'similarity' => round($averageSimilarity * 100, 1),
                    'title_similarity' => round($titleSimilarity * 100, 1),
                    'artist_similarity' => round($artistSimilarity * 100, 1),
                ];
            }
        }

        // 類似度の高い順にソート
        usort($similarSongs, function ($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });

        return $similarSongs;
    }

    /**
     * 正規化後のタイトル・アーティスト名で完全一致する楽曲を検索
     *
     * @param  string  $normalizedTitle  正規化済みタイトル
     * @param  string  $normalizedArtist  正規化済みアーティスト名
     * @return Song|null 一致する楽曲、存在しない場合はnull
     */
    public function findExactMatch(string $normalizedTitle, string $normalizedArtist): ?Song
    {
        // 正規化後のテキストで比較するため、全曲を取得して比較
        $allSongs = Song::all();
        foreach ($allSongs as $song) {
            $songNormalizedTitle = TextNormalizer::normalize($song->title);
            $songNormalizedArtist = TextNormalizer::normalize($song->artist);

            if ($songNormalizedTitle === $normalizedTitle && $songNormalizedArtist === $normalizedArtist) {
                return $song;
            }
        }

        return null;
    }
}
