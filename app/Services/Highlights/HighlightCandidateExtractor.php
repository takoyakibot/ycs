<?php

namespace App\Services\Highlights;

/**
 * 音量・字幕・チャットの3信号から、ハイライト候補区間を機械的に抽出する。
 *
 * 抽出戦略:
 *   1. 動画を WINDOW_SEC 秒幅のウィンドウに区切る
 *   2. 各ウィンドウについて次の3つのスコアを算出
 *      - 音量ピーク（ウィンドウ平均比）
 *      - チャット密度（移動平均比）
 *      - リアクションキーワード密度
 *   3. 各シグナルが閾値を超えたウィンドウを候補とし、合成スコアを付与
 *   4. 隣接候補（MERGE_GAP_SEC 秒以内）はマージ
 *   5. スコア降順で上位 MAX_CANDIDATES 件を返す
 *
 * AI 判定の前段としてコストを抑えるため、候補数を絞り込むことを目的とする。
 */
class HighlightCandidateExtractor
{
    private const WINDOW_SEC = 10;

    private const MERGE_GAP_SEC = 30;

    private const MAX_CANDIDATES = 30;

    private const VOLUME_BUCKET_SEC = 2;

    /**
     * リアクション系キーワード（笑い・驚き・感嘆など）。
     * 値は重み（強い反応ほど高い）。
     */
    private const REACTION_KEYWORDS = [
        // 笑い
        '草' => 1.0,
        'ｗｗｗ' => 1.0,
        'www' => 1.0,
        'ww' => 0.6,
        '笑' => 0.5,
        '爆笑' => 1.2,
        // 驚き
        'えっ' => 0.7,
        'ええっ' => 0.8,
        'えええ' => 0.9,
        'まじ' => 0.6,
        'マジ' => 0.6,
        'やば' => 0.8,
        'ヤバ' => 0.8,
        // 感嘆
        'すごい' => 0.7,
        'スゴ' => 0.7,
        'かわいい' => 0.8,
        'かわよ' => 0.8,
        '神' => 0.8,
        '好き' => 0.5,
        'すき' => 0.5,
        // 感情
        '怖い' => 0.7,
        'こわい' => 0.7,
        '泣' => 0.6,
    ];

    /**
     * 候補区間を抽出する。
     *
     * @param  float  $duration  動画長（秒）
     * @param  array<int, float>  $volumes  2秒バケットの音量データ（0..1）
     * @param  array<int, array{start: float, duration: float, text: string}>  $subtitles
     * @param  array<int, array{offsetMs: int, message: string, isSuperchat?: bool}>  $chats
     * @return array<int, array{
     *     time: int,
     *     end_time: int,
     *     score: float,
     *     volume_score: float,
     *     chat_score: float,
     *     keyword_score: float,
     *     chat_count: int,
     *     reaction_keywords: array<int, string>,
     *     subtitles: array<int, array{start: float, text: string}>,
     *     chats: array<int, array{offsetMs: int, message: string, isSuperchat: bool}>,
     * }>
     */
    public function extract(float $duration, array $volumes, array $subtitles, array $chats): array
    {
        if ($duration <= 0) {
            return [];
        }

        $windowCount = (int) ceil($duration / self::WINDOW_SEC);
        if ($windowCount <= 0) {
            return [];
        }

        $volumePerWindow = $this->aggregateVolumesPerWindow($volumes, $windowCount);
        $chatPerWindow = $this->aggregateChatsPerWindow($chats, $windowCount);
        $keywordPerWindow = $this->aggregateKeywordsPerWindow($chats, $windowCount);

        $volumeStats = $this->computeStats($volumePerWindow);
        $chatStats = $this->computeStats(array_map(fn ($w) => (float) $w['count'], $chatPerWindow));

        $rawCandidates = [];
        for ($i = 0; $i < $windowCount; $i++) {
            $volumeScore = $this->scoreAgainstStats($volumePerWindow[$i], $volumeStats, 1.5);
            $chatScore = $this->scoreAgainstStats((float) $chatPerWindow[$i]['count'], $chatStats, 1.5);
            $keywordScore = $keywordPerWindow[$i]['weight'];

            // 3信号のいずれかが閾値超え（または極端な値）なら候補とする
            if ($volumeScore <= 0 && $chatScore <= 0 && $keywordScore <= 0) {
                continue;
            }

            $score = $volumeScore * 0.35 + $chatScore * 0.40 + $keywordScore * 0.25;
            if ($score <= 0) {
                continue;
            }

            $rawCandidates[] = [
                'window_index' => $i,
                'time' => $i * self::WINDOW_SEC,
                'end_time' => min($duration, ($i + 1) * self::WINDOW_SEC),
                'score' => $score,
                'volume_score' => $volumeScore,
                'chat_score' => $chatScore,
                'keyword_score' => $keywordScore,
                'chat_count' => $chatPerWindow[$i]['count'],
                'reaction_keywords' => array_keys($keywordPerWindow[$i]['hits']),
            ];
        }

        $merged = $this->mergeAdjacent($rawCandidates);

        usort($merged, fn ($a, $b) => $b['score'] <=> $a['score']);
        $merged = array_slice($merged, 0, self::MAX_CANDIDATES);

        // 各候補に周辺の字幕・チャットを付与
        foreach ($merged as &$candidate) {
            $candidate['subtitles'] = $this->collectSubtitles($subtitles, $candidate['time'], $candidate['end_time']);
            $candidate['chats'] = $this->collectChats($chats, $candidate['time'], $candidate['end_time']);
        }
        unset($candidate);

        // 時刻順に並べ替えて返却
        usort($merged, fn ($a, $b) => $a['time'] <=> $b['time']);

        return $merged;
    }

    /**
     * 2秒バケットの音量データをウィンドウ単位に集約（最大値）。
     *
     * @param  array<int, float>  $volumes
     * @return array<int, float>
     */
    private function aggregateVolumesPerWindow(array $volumes, int $windowCount): array
    {
        $result = array_fill(0, $windowCount, 0.0);
        if (empty($volumes)) {
            return $result;
        }

        $bucketsPerWindow = (int) (self::WINDOW_SEC / self::VOLUME_BUCKET_SEC);
        foreach ($volumes as $idx => $v) {
            $windowIndex = (int) floor($idx / $bucketsPerWindow);
            if ($windowIndex < 0 || $windowIndex >= $windowCount) {
                continue;
            }
            if ($v > $result[$windowIndex]) {
                $result[$windowIndex] = (float) $v;
            }
        }

        return $result;
    }

    /**
     * チャット件数をウィンドウ単位に集約。
     *
     * @param  array<int, array{offsetMs: int, message: string, isSuperchat?: bool}>  $chats
     * @return array<int, array{count: int}>
     */
    private function aggregateChatsPerWindow(array $chats, int $windowCount): array
    {
        $result = array_fill(0, $windowCount, ['count' => 0]);
        foreach ($chats as $chat) {
            $sec = ($chat['offsetMs'] ?? 0) / 1000.0;
            $windowIndex = (int) floor($sec / self::WINDOW_SEC);
            if ($windowIndex < 0 || $windowIndex >= $windowCount) {
                continue;
            }
            // スーパーチャットは強い反応として3倍カウント
            $weight = ! empty($chat['isSuperchat']) ? 3 : 1;
            $result[$windowIndex]['count'] += $weight;
        }

        return $result;
    }

    /**
     * リアクションキーワード重みをウィンドウ単位に集約。
     *
     * @param  array<int, array{offsetMs: int, message: string, isSuperchat?: bool}>  $chats
     * @return array<int, array{weight: float, hits: array<string, int>}>
     */
    private function aggregateKeywordsPerWindow(array $chats, int $windowCount): array
    {
        $result = [];
        for ($i = 0; $i < $windowCount; $i++) {
            $result[$i] = ['weight' => 0.0, 'hits' => []];
        }

        foreach ($chats as $chat) {
            $sec = ($chat['offsetMs'] ?? 0) / 1000.0;
            $windowIndex = (int) floor($sec / self::WINDOW_SEC);
            if ($windowIndex < 0 || $windowIndex >= $windowCount) {
                continue;
            }
            $message = (string) ($chat['message'] ?? '');
            if ($message === '') {
                continue;
            }
            foreach (self::REACTION_KEYWORDS as $keyword => $weight) {
                $count = substr_count($message, $keyword);
                if ($count > 0) {
                    $result[$windowIndex]['weight'] += $weight * $count;
                    $result[$windowIndex]['hits'][$keyword] = ($result[$windowIndex]['hits'][$keyword] ?? 0) + $count;
                }
            }
        }

        // 各ウィンドウのキーワード重みを log スケールで正規化（外れ値耐性）
        foreach ($result as &$entry) {
            $entry['weight'] = $entry['weight'] > 0 ? log(1 + $entry['weight']) : 0.0;
        }
        unset($entry);

        return $result;
    }

    /**
     * 配列の平均と標準偏差を計算。
     *
     * @param  array<int, float>  $values
     * @return array{mean: float, std: float}
     */
    private function computeStats(array $values): array
    {
        $n = count($values);
        if ($n === 0) {
            return ['mean' => 0.0, 'std' => 0.0];
        }
        $mean = array_sum($values) / $n;
        $variance = 0.0;
        foreach ($values as $v) {
            $variance += ($v - $mean) ** 2;
        }
        $variance /= $n;

        return ['mean' => $mean, 'std' => sqrt($variance)];
    }

    /**
     * mean + sigmaMultiplier * std を超えた分を 0..1 にマップしてスコアとする。
     */
    private function scoreAgainstStats(float $value, array $stats, float $sigmaMultiplier): float
    {
        $threshold = $stats['mean'] + $sigmaMultiplier * $stats['std'];
        if ($stats['std'] <= 0 || $value <= $threshold) {
            return 0.0;
        }
        // 閾値からの超過度を、平均+3σ で 1.0 になるよう線形マップ
        $cap = $stats['mean'] + 3.0 * $stats['std'];
        if ($cap <= $threshold) {
            return 1.0;
        }
        $ratio = ($value - $threshold) / ($cap - $threshold);

        return min(1.0, max(0.0, $ratio));
    }

    /**
     * 近接ウィンドウをマージ。
     */
    private function mergeAdjacent(array $candidates): array
    {
        if (empty($candidates)) {
            return [];
        }

        usort($candidates, fn ($a, $b) => $a['time'] <=> $b['time']);

        $merged = [];
        $current = null;
        foreach ($candidates as $candidate) {
            if ($current === null) {
                $current = $candidate;

                continue;
            }
            if ($candidate['time'] - $current['end_time'] <= self::MERGE_GAP_SEC) {
                $current['end_time'] = max($current['end_time'], $candidate['end_time']);
                // 強いほうを基準に弱いほうの10%を加算してマージ強度を表現。上限1.0でキャップ。
                $current['score'] = min(1.0, max($current['score'], $candidate['score']) + 0.1 * min($current['score'], $candidate['score']));
                $current['volume_score'] = max($current['volume_score'], $candidate['volume_score']);
                $current['chat_score'] = max($current['chat_score'], $candidate['chat_score']);
                $current['keyword_score'] = max($current['keyword_score'], $candidate['keyword_score']);
                $current['chat_count'] += $candidate['chat_count'];
                $current['reaction_keywords'] = array_values(array_unique(array_merge(
                    $current['reaction_keywords'],
                    $candidate['reaction_keywords']
                )));
            } else {
                $merged[] = $current;
                $current = $candidate;
            }
        }
        if ($current !== null) {
            $merged[] = $current;
        }

        return $merged;
    }

    /**
     * 指定時間範囲内の字幕を抽出。
     *
     * @param  array<int, array{start: float, duration: float, text: string}>  $subtitles
     * @return array<int, array{start: float, text: string}>
     */
    private function collectSubtitles(array $subtitles, int $startSec, int $endSec): array
    {
        $result = [];
        foreach ($subtitles as $sub) {
            $start = (float) ($sub['start'] ?? 0);
            if ($start >= $startSec && $start < $endSec) {
                $result[] = [
                    'start' => $start,
                    'text' => (string) ($sub['text'] ?? ''),
                ];
            }
        }

        return $result;
    }

    /**
     * 指定時間範囲内のチャットを抽出。
     *
     * @param  array<int, array{offsetMs: int, message: string, isSuperchat?: bool}>  $chats
     * @return array<int, array{offsetMs: int, message: string, isSuperchat: bool}>
     */
    private function collectChats(array $chats, int $startSec, int $endSec): array
    {
        $result = [];
        $startMs = $startSec * 1000;
        $endMs = $endSec * 1000;
        foreach ($chats as $chat) {
            $offset = (int) ($chat['offsetMs'] ?? 0);
            if ($offset >= $startMs && $offset < $endMs) {
                $result[] = [
                    'offsetMs' => $offset,
                    'message' => (string) ($chat['message'] ?? ''),
                    'isSuperchat' => ! empty($chat['isSuperchat']),
                ];
            }
        }

        return $result;
    }
}
