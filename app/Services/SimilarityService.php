<?php

namespace App\Services;

class SimilarityService
{
    /**
     * 類似度計算で扱う最大文字数
     *
     * レーベンシュタイン距離の計算量は O(n * m) のため、
     * 極端に長い文字列は先頭からこの文字数までで打ち切る。
     */
    private const MAX_LENGTH = 255;

    /**
     * 2つの文字列の類似度を計算（0.0 ~ 1.0）
     *
     * PHP標準の levenshtein() はバイト単位で距離を計算するため、
     * 1文字が複数バイトになる日本語では距離が実際の文字数の差より
     * 大きく算出され、類似度が不当に低くなる。
     * （例: "ロキ" と "「ロキ」" は1文字差だが levenshtein() では距離6）
     * そのため文字単位で距離を計算する独自実装を使用する。
     *
     * @param  string  $str1  比較する文字列1
     * @param  string  $str2  比較する文字列2
     * @return float 類似度（0.0 ~ 1.0）
     */
    public function calculateSimilarity(string $str1, string $str2): float
    {
        if ($str1 === '' || $str2 === '') {
            return 0.0;
        }

        // 計算量を抑えるため、長すぎる文字列は文字単位で切り詰める
        $str1 = mb_substr($str1, 0, self::MAX_LENGTH, 'UTF-8');
        $str2 = mb_substr($str2, 0, self::MAX_LENGTH, 'UTF-8');

        $maxLen = max(mb_strlen($str1, 'UTF-8'), mb_strlen($str2, 'UTF-8'));
        if ($maxLen === 0) {
            return 1.0;
        }

        $distance = self::levenshtein($str1, $str2);

        $similarity = 1 - ($distance / $maxLen);

        return max(0.0, min(1.0, $similarity));
    }

    /**
     * 文字単位のレーベンシュタイン距離を計算
     *
     * マルチバイト文字を1文字として扱う。
     * 直前の行のみを保持することでメモリ使用量を O(m) に抑えている。
     */
    public static function levenshtein(string $str1, string $str2): int
    {
        if ($str1 === $str2) {
            return 0;
        }

        $chars1 = self::toChars($str1);
        $chars2 = self::toChars($str2);

        $len1 = count($chars1);
        $len2 = count($chars2);

        if ($len1 === 0) {
            return $len2;
        }
        if ($len2 === 0) {
            return $len1;
        }

        // 直前の行（$chars2 側の各位置までの距離）
        $previousRow = range(0, $len2);

        for ($i = 0; $i < $len1; $i++) {
            $currentRow = [$i + 1];

            for ($j = 0; $j < $len2; $j++) {
                $cost = $chars1[$i] === $chars2[$j] ? 0 : 1;

                $currentRow[$j + 1] = min(
                    $currentRow[$j] + 1,          // 挿入
                    $previousRow[$j + 1] + 1,     // 削除
                    $previousRow[$j] + $cost      // 置換
                );
            }

            $previousRow = $currentRow;
        }

        return $previousRow[$len2];
    }

    /**
     * 文字列を1文字ずつの配列に分解
     *
     * @return string[]
     */
    private static function toChars(string $text): array
    {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return $chars === false ? [] : $chars;
    }
}
