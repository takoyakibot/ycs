<?php

namespace App\Services;

use App\Helpers\TextNormalizer;
use App\Models\Song;

/**
 * タイムスタンプのテキストと楽曲マスタを照合するサービス
 *
 * 従来は「テキストからノイズを除去してマスタと完全一致させる」方式だったが、
 * タイムスタンプに付与される装飾（♪、絵文字、【】、曲番号、時間範囲など）は
 * 種類が無限にあり、除去パターンの列挙では追従できない。
 *
 * そこで判定を反転させ、「マスタのタイトルがテキストの中に出現するか」を見る。
 * ノイズが何であるかを定義する必要がなくなるため、未知の装飾にも対応できる。
 *
 * アーティスト名は照合の必須条件にしない。同じ楽曲でも歌唱者・作曲者・グループ名・
 * ボカロ名のどれを書くかは投稿者によって異なるため、一致した場合のみ加点し、
 * 不一致でも減点しない。
 *
 * 楽曲マスタで照合できない場合は、紐付け済みマッピングの辞書
 * （MappingDictionaryService）も照合対象に含める。
 */
class SongMatchingService
{
    /**
     * 候補の由来: 楽曲マスタとの照合
     */
    public const SOURCE_MASTER = 'master';

    /**
     * 候補の由来: 紐付け済みマッピングの辞書との照合
     */
    public const SOURCE_DICTIONARY = 'dictionary';

    /**
     * 照合対象とするタイトルキーの最小文字数
     *
     * 1文字のキーはあらゆるテキストに含まれてしまい照合の意味を持たない。
     */
    private const MIN_TITLE_KEY_LENGTH = 2;

    /**
     * 加点対象とするアーティストトークンの最小文字数
     */
    private const MIN_ARTIST_TOKEN_LENGTH = 2;

    /**
     * 信頼度: 照合キーが完全一致
     */
    public const CONFIDENCE_EXACT = 0.95;

    /**
     * 信頼度: アーティスト名も一致
     */
    public const CONFIDENCE_ARTIST_MATCH = 0.90;

    /**
     * 信頼度: ノイズがごく僅か
     */
    public const CONFIDENCE_HIGH_COVERAGE = 0.85;

    /**
     * 信頼度: 長いタイトルが十分な割合を占める
     */
    public const CONFIDENCE_LONG_TITLE_COVERAGE = 0.80;

    /**
     * 信頼度: 長いタイトルが含まれる（偶然の一致は稀）
     */
    public const CONFIDENCE_LONG_TITLE = 0.70;

    /**
     * 信頼度: 中程度の長さのタイトルが含まれる
     */
    public const CONFIDENCE_MEDIUM_TITLE = 0.50;

    /**
     * 信頼度: 短いタイトルが含まれるだけ（誤爆の可能性が高い）
     */
    public const CONFIDENCE_WEAK = 0.30;

    /**
     * 楽曲マスタの照合キー一覧（メモリキャッシュ）
     *
     * @var array<int, array{id: string, title: string, artist: string, title_key: string, title_key_length: int, artist_token_keys: string[]}>|null
     */
    private ?array $index = null;

    /**
     * 被覆率の算出時に無視するキーワードのキー（メモリキャッシュ）
     *
     * @var string[]|null
     */
    private ?array $ignoreKeywordKeys = null;

    public function __construct(
        protected MappingDictionaryService $mappingDictionaryService
    ) {}

    /**
     * テキストに一致する楽曲候補を信頼度の高い順に返す
     *
     * 楽曲マスタとの照合結果に、紐付け済みマッピングの辞書との照合結果を統合する。
     * 同じ楽曲が両方から得られた場合は信頼度の高いほうを採用する。
     *
     * @param  string  $text  タイムスタンプのテキスト（生テキストでも正規化済みでも可）
     * @param  int|null  $limit  返却する候補数の上限
     * @return array<int, array{song_id: string, title: string, artist: string, confidence: float, coverage: float|null, artist_hit: bool|null, matched_key: string|null, source: string, source_text: string|null, similarity: float|null}>
     */
    public function findCandidates(string $text, ?int $limit = null): array
    {
        $limit = $limit ?? (int) config('songs.matching.candidate_limit', 5);

        $candidates = $this->findMasterCandidates($text);

        // 楽曲マスタで得られなかった楽曲を辞書から補う
        foreach ($this->mappingDictionaryService->findCandidates($text, $limit) as $entry) {
            if (isset($candidates[$entry['song_id']])
                && $candidates[$entry['song_id']]['confidence'] >= $entry['confidence']
            ) {
                continue;
            }

            $candidates[$entry['song_id']] = [
                'song_id' => $entry['song_id'],
                'title' => $entry['title'],
                'artist' => $entry['artist'],
                'confidence' => $entry['confidence'],
                'coverage' => null,
                'artist_hit' => null,
                'matched_key' => null,
                'source' => self::SOURCE_DICTIONARY,
                'source_text' => $entry['source_text'],
                'similarity' => $entry['similarity'],
            ];
        }

        $candidates = array_values($candidates);

        // 信頼度 → タイトルキーの長さ（長い一致のほうが確実）の順に並べる
        usort($candidates, function ($a, $b) {
            return [$b['confidence'], mb_strlen((string) $b['matched_key'])]
                <=> [$a['confidence'], mb_strlen((string) $a['matched_key'])];
        });

        return array_slice($candidates, 0, $limit);
    }

    /**
     * 楽曲マスタとの照合候補を楽曲IDをキーにして返す
     *
     * @return array<string, array{song_id: string, title: string, artist: string, confidence: float, coverage: float, artist_hit: bool, matched_key: string, source: string, source_text: null, similarity: null}>
     */
    private function findMasterCandidates(string $text): array
    {
        $textKey = TextNormalizer::matchKey($text);
        if ($textKey === '') {
            return [];
        }

        // 被覆率の分母は、カバー表記などの定型キーワードを除いた長さを用いる
        // （"曲名 (cover)" の被覆率が不当に下がるのを防ぐ）
        $effectiveLength = $this->calculateEffectiveLength($textKey);

        $candidates = [];

        foreach ($this->getIndex() as $entry) {
            if ($entry['title_key_length'] < self::MIN_TITLE_KEY_LENGTH) {
                continue;
            }

            if (mb_strpos($textKey, $entry['title_key'], 0, 'UTF-8') === false) {
                continue;
            }

            $coverage = min(1.0, $entry['title_key_length'] / $effectiveLength);
            $artistHit = $this->hasArtistHit($textKey, $entry['artist_token_keys']);
            $confidence = $this->scoreCandidate($coverage, $entry['title_key_length'], $artistHit);

            // 同じ楽曲が複数のキーで一致した場合は信頼度の高いほうを残す
            if (isset($candidates[$entry['id']]) && $candidates[$entry['id']]['confidence'] >= $confidence) {
                continue;
            }

            $candidates[$entry['id']] = [
                'song_id' => $entry['id'],
                'title' => $entry['title'],
                'artist' => $entry['artist'],
                'confidence' => $confidence,
                'coverage' => round($coverage, 4),
                'artist_hit' => $artistHit,
                'matched_key' => $entry['title_key'],
                'source' => self::SOURCE_MASTER,
                'source_text' => null,
                'similarity' => null,
            ];
        }

        return $candidates;
    }

    /**
     * 自動紐付けに使える一意な最有力候補を返す
     *
     * 信頼度が閾値未満の場合、および同じ信頼度の候補が複数ある場合はnullを返す。
     * 曖昧なまま自動で紐付けると誤りの一括生成につながるため、
     * その場合は候補提示に留めて人間の判断に委ねる。
     *
     * @return array{song_id: string, title: string, artist: string, confidence: float, coverage: float, artist_hit: bool, matched_key: string}|null
     */
    public function findBestMatch(string $text): ?array
    {
        $threshold = (float) config('songs.matching.auto_link_threshold', 0.85);

        // 同信頼度の競合を検出するため、上限より多めに取得する
        $candidates = $this->findCandidates($text, 5);

        if (empty($candidates)) {
            return null;
        }

        $best = $candidates[0];

        if ($best['confidence'] < $threshold) {
            return null;
        }

        // 同じ信頼度で別の楽曲が並んでいる場合は曖昧と判断する
        if (isset($candidates[1])
            && $candidates[1]['song_id'] !== $best['song_id']
            && $candidates[1]['confidence'] === $best['confidence']
        ) {
            return null;
        }

        return $best;
    }

    /**
     * 候補の信頼度を算出
     *
     * 単一の計算式ではなく段階的な条件で決める。
     * 「アーティストも一致した」「タイトルが長い」といった判断根拠が
     * そのまま信頼度に対応するため、閾値の調整と検証がしやすい。
     *
     * @param  float  $coverage  タイトルキーがテキスト全体に占める割合
     * @param  int  $titleKeyLength  タイトルキーの文字数
     * @param  bool  $artistHit  アーティスト名も一致したか
     */
    private function scoreCandidate(float $coverage, int $titleKeyLength, bool $artistHit): float
    {
        if ($coverage >= 1.0) {
            return self::CONFIDENCE_EXACT;
        }

        if ($artistHit) {
            return self::CONFIDENCE_ARTIST_MATCH;
        }

        if ($coverage >= 0.8 && $titleKeyLength >= 3) {
            return self::CONFIDENCE_HIGH_COVERAGE;
        }

        if ($coverage >= 0.6 && $titleKeyLength >= 8) {
            return self::CONFIDENCE_LONG_TITLE_COVERAGE;
        }

        if ($titleKeyLength >= 8) {
            return self::CONFIDENCE_LONG_TITLE;
        }

        if ($titleKeyLength >= 4) {
            return self::CONFIDENCE_MEDIUM_TITLE;
        }

        return self::CONFIDENCE_WEAK;
    }

    /**
     * アーティストトークンのいずれかがテキストに含まれるか判定
     *
     * @param  string[]  $artistTokenKeys
     */
    private function hasArtistHit(string $textKey, array $artistTokenKeys): bool
    {
        foreach ($artistTokenKeys as $tokenKey) {
            if (mb_strpos($textKey, $tokenKey, 0, 'UTF-8') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 被覆率の分母となる文字数を算出
     *
     * カバー表記などの定型キーワードは楽曲の識別に寄与しないため、
     * その文字数を差し引いた長さを用いる。
     */
    private function calculateEffectiveLength(string $textKey): int
    {
        $stripped = $textKey;

        foreach ($this->getIgnoreKeywordKeys() as $keywordKey) {
            $stripped = str_replace($keywordKey, '', $stripped);
        }

        return max(1, mb_strlen($stripped, 'UTF-8'));
    }

    /**
     * 楽曲マスタの照合キー一覧を取得（初回のみDBから読み込む）
     *
     * 楽曲マスタの規模では全件をメモリに載せて総当たりで照合しても
     * 十分に高速なため、候補絞り込み用のインデックスは持たない。
     *
     * @return array<int, array{id: string, title: string, artist: string, title_key: string, title_key_length: int, artist_token_keys: string[]}>
     */
    private function getIndex(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $this->index = [];

        Song::query()
            ->select(['id', 'title', 'artist', 'match_key_title', 'match_key_artist'])
            ->orderBy('id')
            ->chunk(500, function ($songs) {
                foreach ($songs as $song) {
                    // カラムが未設定の場合（マイグレーション前に作られた行など）は都度算出する
                    $titleKey = $song->match_key_title ?? TextNormalizer::matchKey($song->title);

                    if ($titleKey === '') {
                        continue;
                    }

                    $this->index[] = [
                        'id' => $song->id,
                        'title' => (string) $song->title,
                        'artist' => (string) $song->artist,
                        'title_key' => $titleKey,
                        'title_key_length' => mb_strlen($titleKey, 'UTF-8'),
                        'artist_token_keys' => $this->buildArtistTokenKeys($song),
                    ];
                }
            });

        return $this->index;
    }

    /**
     * アーティスト名から照合用トークンキーを構築
     *
     * @return string[]
     */
    private function buildArtistTokenKeys(Song $song): array
    {
        $keys = [];

        foreach (TextNormalizer::splitArtistTokens($song->artist) as $token) {
            $tokenKey = TextNormalizer::matchKey($token);
            if (mb_strlen($tokenKey, 'UTF-8') >= self::MIN_ARTIST_TOKEN_LENGTH) {
                $keys[$tokenKey] = true;
            }
        }

        // カラムに保持している全体のキーも候補に含める
        $artistKey = $song->match_key_artist ?? TextNormalizer::matchKey($song->artist);
        if (mb_strlen($artistKey, 'UTF-8') >= self::MIN_ARTIST_TOKEN_LENGTH) {
            $keys[$artistKey] = true;
        }

        return array_keys($keys);
    }

    /**
     * 被覆率算出で無視するキーワードのキーを取得
     *
     * @return string[]
     */
    private function getIgnoreKeywordKeys(): array
    {
        if ($this->ignoreKeywordKeys !== null) {
            return $this->ignoreKeywordKeys;
        }

        $keys = [];
        foreach (TextNormalizer::getIgnoreKeywords() as $keyword) {
            $keywordKey = TextNormalizer::matchKey($keyword);
            if ($keywordKey !== '') {
                $keys[] = $keywordKey;
            }
        }

        // 長いキーワードから先に除去する（"shorts" が "short" で削られないように）
        usort($keys, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return $this->ignoreKeywordKeys = $keys;
    }

    /**
     * メモリキャッシュを破棄する
     *
     * 楽曲マスタやマッピングを更新したあとに再照合する場合に使用する。
     * 辞書側のキャッシュも同時に破棄する。
     */
    public function flushIndex(): void
    {
        $this->index = null;
        $this->mappingDictionaryService->flushIndex();
    }
}
