<?php

namespace App\Services;

use App\Helpers\TextNormalizer;
use Illuminate\Support\Str;

class TimestampExtractorService
{
    /**
     * 除去パターンを適用してテキストから装飾文字を除去する
     *
     * @param  string  $text  元テキスト
     * @param  array  $stripPatterns  除去パターンの配列。各要素は ['pattern' => string, 'is_regex' => bool] または文字列
     * @return string 除去後のテキスト
     */
    public static function applyStripPatterns(string $text, array $stripPatterns): string
    {
        foreach ($stripPatterns as $item) {
            if (is_array($item)) {
                $pattern = $item['pattern'];
                $isRegex = $item['is_regex'] ?? false;
            } else {
                $pattern = $item;
                $isRegex = false;
            }

            if ($isRegex) {
                $result = @preg_replace($pattern, '', $text);
                if ($result !== null) {
                    $text = $result;
                }
            } else {
                $text = str_replace($pattern, '', $text);
            }
        }

        return trim($text);
    }

    /**
     * テキストからタイムスタンプを抽出
     *
     * @param  string  $videoId  動画ID
     * @param  string  $type  タイプ（1: description, 2: comment）
     * @param  string  $description  抽出対象のテキスト
     * @param  string  $commentId  コメントID
     * @param  array  $stripPatterns  除去パターンの配列。各要素は ['pattern' => string, 'is_regex' => bool] または文字列
     * @return array タイムスタンプ配列
     */
    public function extractTimestamps(string $videoId, string $type, string $description, string $commentId, array $stripPatterns = []): array
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
                $timestamp = $matches[1]; // タイムスタンプ部分（範囲表記の場合は開始時刻）

                // タイムスタンプ表現全体をtextから除去する（#627）
                // - 範囲表記「開始〜終了」は終了時刻ごと除去（textに終了時刻が混入すると
                //   正規化テキストが汚れて楽曲マッチングを阻害する）
                // - 「[mm:ss] 曲名」のような括弧囲みは、対の括弧で囲まれている場合のみ括弧ごと除去
                //   （曲名側の括弧や対になっていない括弧は誤除去しない）
                $quoted = preg_quote($timestamp, '/');
                $tsPattern = '\d{1,2}:\d{2}(?::\d{2})?';
                $range = $quoted.'(?:\s*[〜~～\-－–—→]\s*'.$tsPattern.')?';
                $removePattern = '/(?:'
                    .'\[\s*'.$range.'\s*\]'
                    .'|［\s*'.$range.'\s*］'
                    .'|\(\s*'.$range.'\s*\)'
                    .'|（\s*'.$range.'\s*）'
                    .'|【\s*'.$range.'\s*】'
                    .'|'.$range
                    .')/u';
                $comment = trim(preg_replace($removePattern, '', $line, 1));
                // 先頭の全角スペースを除外
                $comment = TextNormalizer::trimFullwidthSpace($comment);

                // 不正なUTF-8をサニタイズしてから保存
                $sanitizedComment = TextNormalizer::sanitizeUtf8($comment);

                // 除去パターンを適用してからnormalize（textは元のまま保持）
                $textForNormalize = ! empty($stripPatterns)
                    ? self::applyStripPatterns($sanitizedComment, $stripPatterns)
                    : $sanitizedComment;

                // 結果に追加
                $results[] = [
                    'id' => Str::ulid(),
                    'comment_id' => $commentId,
                    'video_id' => $videoId,
                    'type' => $type,
                    'ts_text' => $timestamp,
                    'ts_num' => $this->timestampToSeconds($timestamp),
                    'text' => $sanitizedComment,
                    'normalized_text' => TextNormalizer::normalize($textForNormalize),
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
