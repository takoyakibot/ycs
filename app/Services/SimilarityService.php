<?php

namespace App\Services;

class SimilarityService
{
    /**
     * 2つの文字列の類似度を計算（0.0 ~ 1.0）
     *
     * @param  string  $str1  比較する文字列1
     * @param  string  $str2  比較する文字列2
     * @return float 類似度（0.0 ~ 1.0）
     */
    public function calculateSimilarity(string $str1, string $str2): float
    {
        if (empty($str1) || empty($str2)) {
            return 0.0;
        }

        // Levenshtein距離を使用（255文字制限あり）
        $maxLen = max(mb_strlen($str1), mb_strlen($str2));
        if ($maxLen === 0) {
            return 1.0;
        }

        // levenshtein()は255文字までしか対応していないため、超える場合は切り詰める
        if (strlen($str1) > 255 || strlen($str2) > 255) {
            $str1 = substr($str1, 0, 255);
            $str2 = substr($str2, 0, 255);
        }

        $distance = levenshtein($str1, $str2);

        // levenshtein()が失敗した場合（-1を返す）は類似度0とする
        if ($distance === -1) {
            return 0.0;
        }

        $similarity = 1 - ($distance / $maxLen);

        return max(0.0, min(1.0, $similarity));
    }
}
