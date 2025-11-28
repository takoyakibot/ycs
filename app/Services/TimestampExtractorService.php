<?php

namespace App\Services;

use App\Helpers\TextNormalizer;
use Illuminate\Support\Str;

class TimestampExtractorService
{
    /**
     * テキストからタイムスタンプを抽出
     *
     * @param  string  $videoId  動画ID
     * @param  string  $type  タイプ（1: description, 2: comment）
     * @param  string  $description  抽出対象のテキスト
     * @param  string  $commentId  コメントID
     * @return array タイムスタンプ配列
     */
    public function extractTimestamps(string $videoId, string $type, string $description, string $commentId): array
    {
        // 引数のバリデーション
        if (! is_string($videoId) || ! is_string($description)) {
            error_log('Invalid video_id or description: '
                .var_export($videoId, true).', '.var_export($description, true));

            return [];
        }

        if (! in_array($type, ['1', '2'])) {
            error_log('Invalid type: '.var_export($type, true));

            return [];
        }

        // 正規表現でタイムスタンプを抽出 (MM:SS または HH:MM:SS)
        $pattern = '/\b(\d{1,2}:\d{2}(?::\d{2})?)\b/';
        $lines = explode("\n", $description); // 改行で分割
        $results = [];

        foreach ($lines as $line) {
            // 各行からタイムスタンプを抽出
            if (preg_match($pattern, $line, $matches)) {
                $timestamp = $matches[1];                              // タイムスタンプ部分
                $comment = trim(str_replace($timestamp, '', $line)); // タイムスタンプを除外した部分
                // 先頭の全角スペースを除外
                $comment = TextNormalizer::trimFullwidthSpace($comment);

                // 結果に追加
                $results[] = [
                    'id' => Str::ulid(),
                    'comment_id' => $commentId,
                    'video_id' => $videoId,
                    'type' => $type,
                    'ts_text' => $timestamp,
                    'ts_num' => $this->timestampToSeconds($timestamp),
                    'text' => $comment,
                ];
            }
        }

        return $results;
    }

    /**
     * タイムスタンプ文字列を秒数に変換
     *
     * @param  string  $timestamp  タイムスタンプ (MM:SS または HH:MM:SS)
     * @return int 秒数
     */
    public function timestampToSeconds(string $timestamp): int
    {
        $parts = explode(':', $timestamp);
        $count = count($parts);

        if ($count === 2) {
            return ($parts[0] * 60) + $parts[1];
        } elseif ($count === 3) {
            return ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2];
        }

        return 0; // 不正なフォーマットの場合
    }
}
