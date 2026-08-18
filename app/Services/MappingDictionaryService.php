<?php

namespace App\Services;

use App\Helpers\TextNormalizer;
use App\Models\TimestampSongMapping;
use Illuminate\Support\Facades\Cache;

/**
 * 既存の紐付け済みマッピングを辞書として照合するサービス
 *
 * timestamp_song_mappings には「実際のタイムスタンプの表記」と「楽曲」の
 * 対応が蓄積されている。楽曲マスタと違って装飾やアーティスト表記の揺らぎを
 * 含んだ現実のテキストであり、照合の材料としてはマスタより実データに近い。
 *
 * 対象は手動で確定されたマッピングのみに限定する。
 * 自動紐付けの結果を辞書に含めると、誤った紐付けを根拠に次の紐付けが行われ、
 * 誤りが連鎖して広がってしまう。
 */
class MappingDictionaryService
{
    /**
     * 辞書のキャッシュキー
     *
     * 正規化画面の候補提示はリクエストごとに実行されるため、
     * 毎回マッピング全件を読み直さないようキャッシュ層を挟む。
     * TimestampSongMapping・Songモデルの保存・削除時に無効化される。
     */
    public const CACHE_KEY = 'song_matching.dictionary_index';

    /**
     * 類似度判定の対象とするキーの最小文字数
     */
    private const MIN_KEY_LENGTH = 2;

    /**
     * 候補を絞り込むためのプレフィックス長
     *
     * 辞書は件数が多くなるため全件との類似度計算は行わず、
     * 照合キーの先頭が一致するものだけを比較対象にする。
     */
    private const BUCKET_PREFIX_LENGTH = 2;

    /**
     * 1つのバケット内で類似度を計算する最大件数
     *
     * 同じ先頭文字を持つ表記が大量にある場合の処理時間を抑える。
     */
    private const MAX_COMPARISONS_PER_BUCKET = 200;

    /**
     * 許容するキー長の差
     *
     * 文字数が大きく異なる表記は類似度が閾値に届かないため、
     * 計算する前に除外する。
     */
    private const MAX_LENGTH_DIFFERENCE = 4;

    /**
     * 信頼度: 装飾を除いたキーが完全に一致
     */
    public const CONFIDENCE_KEY_MATCH = 0.95;

    /**
     * 信頼度: 表記が非常に近い
     */
    public const CONFIDENCE_HIGH_SIMILARITY = 0.85;

    /**
     * 信頼度: 表記が近い（候補提示に留める水準）
     */
    public const CONFIDENCE_SIMILARITY = 0.60;

    /**
     * 楽曲IDごとの楽曲情報
     *
     * 1つの楽曲に多数の表記が対応するため、タイトルとアーティスト名は
     * エントリごとに持たずここで一元管理する（辞書が数万件規模になるため）。
     *
     * @var array<string, array{title: string, artist: string}>|null
     */
    private ?array $songs = null;

    /**
     * 1つの照合キーに対して保持する楽曲数の上限
     *
     * 同じキーが多数の楽曲を指す状態は照合として意味を持たないため打ち切る。
     */
    private const MAX_SONGS_PER_KEY = 5;

    /**
     * 照合キーが完全一致する辞書エントリ
     *
     * 同じキーが異なる楽曲を指す場合はすべて保持する。
     * 先勝ちで1件に絞ると、キーが衝突していること自体が候補から見えなくなり、
     * 呼び出し側の「同信頼度の候補が並んだら自動紐付けしない」という
     * 曖昧性の判定が機能しなくなるため。
     *
     * @var array<string, array<int, array{song_id: string, source_text: string}>>|null
     */
    private ?array $exactIndex = null;

    /**
     * 照合キーの先頭N文字でまとめた辞書エントリ
     *
     * @var array<string, array<int, array{song_id: string, source_text: string, key: string, key_length: int}>>|null
     */
    private ?array $buckets = null;

    public function __construct(
        protected SimilarityService $similarityService
    ) {}

    /**
     * 辞書から候補を信頼度の高い順に返す
     *
     * @param  string  $normalizedText  照合対象の正規化済みテキスト
     * @param  int|null  $limit  返却する候補数の上限
     * @return array<int, array{song_id: string, title: string, artist: string, confidence: float, source_text: string, similarity: float}>
     */
    public function findCandidates(string $normalizedText, ?int $limit = null): array
    {
        $limit = $limit ?? (int) config('songs.matching.candidate_limit', 5);

        $key = TextNormalizer::matchKey($normalizedText);
        if (mb_strlen($key, 'UTF-8') < self::MIN_KEY_LENGTH) {
            return [];
        }

        $this->loadIndex();

        $candidates = [];

        // 1. 装飾を除いたキーの完全一致
        // 元テキストの表記が違うため既存のJOINでは紐付かないが、
        // 装飾を除けば同一のテキストである、というケースを拾う
        foreach ($this->exactIndex[$key] ?? [] as $entry) {
            $candidates[$entry['song_id']] = $this->buildCandidate(
                $entry['song_id'],
                self::CONFIDENCE_KEY_MATCH,
                $entry['source_text'],
                1.0
            );
        }

        // 2. 表記の揺らぎを類似度で拾う
        foreach ($this->findSimilarEntries($key) as $similar) {
            $songId = $similar['song_id'];

            // 完全一致で既に採用済みの楽曲は上書きしない
            if (isset($candidates[$songId])) {
                continue;
            }

            $candidates[$songId] = $similar;
        }

        $candidates = array_values($candidates);

        usort($candidates, fn ($a, $b) => [$b['confidence'], $b['similarity']] <=> [$a['confidence'], $a['similarity']]);

        return array_slice($candidates, 0, $limit);
    }

    /**
     * 類似する辞書エントリを探す
     *
     * @return array<int, array{song_id: string, title: string, artist: string, confidence: float, source_text: string, similarity: float}>
     */
    private function findSimilarEntries(string $key): array
    {
        $threshold = (float) config('songs.matching.dictionary_similarity_threshold', 0.8);

        $bucketKey = mb_substr($key, 0, self::BUCKET_PREFIX_LENGTH, 'UTF-8');
        $entries = $this->buckets[$bucketKey] ?? [];

        if (empty($entries)) {
            return [];
        }

        $keyLength = mb_strlen($key, 'UTF-8');

        $results = [];
        $comparisons = 0;

        foreach ($entries as $entry) {
            if ($comparisons >= self::MAX_COMPARISONS_PER_BUCKET) {
                break;
            }

            if ($entry['key'] === $key) {
                continue;
            }

            if (abs($entry['key_length'] - $keyLength) > self::MAX_LENGTH_DIFFERENCE) {
                continue;
            }

            $comparisons++;

            $similarity = $this->similarityService->calculateSimilarity($key, $entry['key']);
            if ($similarity < $threshold) {
                continue;
            }

            $results[] = $this->buildCandidate(
                $entry['song_id'],
                $similarity >= 0.9 ? self::CONFIDENCE_HIGH_SIMILARITY : self::CONFIDENCE_SIMILARITY,
                $entry['source_text'],
                round($similarity, 4)
            );
        }

        return $results;
    }

    /**
     * 候補の配列を組み立てる
     *
     * @return array{song_id: string, title: string, artist: string, confidence: float, source_text: string, similarity: float}
     */
    private function buildCandidate(string $songId, float $confidence, string $sourceText, float $similarity): array
    {
        return [
            'song_id' => $songId,
            'title' => $this->songs[$songId]['title'] ?? '',
            'artist' => $this->songs[$songId]['artist'] ?? '',
            'confidence' => $confidence,
            'source_text' => $sourceText,
            'similarity' => $similarity,
        ];
    }

    /**
     * 辞書を読み込む（初回のみDBから取得）
     */
    private function loadIndex(): void
    {
        if ($this->exactIndex !== null) {
            return;
        }

        $ttl = (int) config('songs.matching.index_cache_ttl', 600);

        $cached = Cache::remember(self::CACHE_KEY, $ttl, fn () => $this->buildIndex());

        $this->songs = $cached['songs'];
        $this->exactIndex = $cached['exact_index'];
        $this->buckets = $cached['buckets'];
    }

    /**
     * 辞書をDBから構築
     *
     * @return array{songs: array, exact_index: array, buckets: array}
     */
    private function buildIndex(): array
    {
        $songs = [];
        $exactIndex = [];
        $buckets = [];

        TimestampSongMapping::query()
            ->select([
                'timestamp_song_mappings.normalized_text',
                'timestamp_song_mappings.song_id',
                'songs.title',
                'songs.artist',
            ])
            ->join('songs', 'timestamp_song_mappings.song_id', '=', 'songs.id')
            ->whereNotNull('timestamp_song_mappings.song_id')
            ->where('timestamp_song_mappings.is_not_song', false)
            ->where('timestamp_song_mappings.status', TimestampSongMapping::STATUS_LINKED)
            // 自動紐付けの結果は辞書に含めない（誤りの連鎖を防ぐ）
            ->where('timestamp_song_mappings.is_manual', true)
            ->orderBy('timestamp_song_mappings.normalized_text')
            ->chunk(1000, function ($mappings) use (&$songs, &$exactIndex, &$buckets) {
                foreach ($mappings as $mapping) {
                    $key = TextNormalizer::matchKey($mapping->normalized_text);
                    $keyLength = mb_strlen($key, 'UTF-8');

                    if ($keyLength < self::MIN_KEY_LENGTH) {
                        continue;
                    }

                    $songs[$mapping->song_id] ??= [
                        'title' => (string) $mapping->title,
                        'artist' => (string) $mapping->artist,
                    ];

                    $entry = [
                        'song_id' => $mapping->song_id,
                        'source_text' => (string) $mapping->normalized_text,
                    ];

                    // 同じキーが異なる楽曲を指す場合はすべて保持する（曖昧性の検出に必要）。
                    // 先勝ちで1件に絞ると、呼び出し側の「同信頼度の候補が並んだら
                    // 自動紐付けしない」という判定が機能しなくなる。
                    // 同じ楽曲の表記違いは1件で足りるため追加しない
                    $existing = $exactIndex[$key] ?? [];
                    if (count($existing) < self::MAX_SONGS_PER_KEY
                        && ! in_array($mapping->song_id, array_column($existing, 'song_id'), true)
                    ) {
                        $exactIndex[$key][] = $entry;
                    }

                    $bucketKey = mb_substr($key, 0, self::BUCKET_PREFIX_LENGTH, 'UTF-8');
                    $buckets[$bucketKey][] = $entry + [
                        'key' => $key,
                        'key_length' => $keyLength,
                    ];
                }
            });

        return [
            'songs' => $songs,
            'exact_index' => $exactIndex,
            'buckets' => $buckets,
        ];
    }

    /**
     * メモリキャッシュを破棄する
     */
    public function flushIndex(): void
    {
        $this->songs = null;
        $this->exactIndex = null;
        $this->buckets = null;
        Cache::forget(self::CACHE_KEY);
    }
}
