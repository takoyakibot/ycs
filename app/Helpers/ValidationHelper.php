<?php

namespace App\Helpers;

class ValidationHelper
{
    /**
     * Spotify Track IDの妥当性を検証
     *
     * Spotify Track IDは22文字の英数字（大文字・小文字）で構成される
     * 無効なIDの場合はnullを返す
     *
     * @param  string|null  $trackId  検証するTrack ID
     * @return string|null 有効なTrack ID、または無効な場合はnull
     */
    public static function validateSpotifyTrackId(?string $trackId): ?string
    {
        if (! $trackId) {
            return null;
        }

        // Spotify track IDsは22文字の英数字
        if (preg_match('/^[a-zA-Z0-9]{22}$/', $trackId)) {
            return $trackId;
        }

        return null;
    }

    /**
     * Spotify Track IDが有効かどうかをチェック
     *
     * @param  string|null  $trackId  検証するTrack ID
     * @return bool 有効な場合はtrue
     */
    public static function isValidSpotifyTrackId(?string $trackId): bool
    {
        return self::validateSpotifyTrackId($trackId) !== null;
    }

    /**
     * YouTube Video IDの妥当性を検証
     *
     * YouTube Video IDは11文字の英数字、ハイフン、アンダースコアで構成される
     * 無効なIDの場合はnullを返す
     *
     * @param  string|null  $videoId  検証するVideo ID
     * @return string|null 有効なVideo ID、または無効な場合はnull
     */
    public static function validateYouTubeVideoId(?string $videoId): ?string
    {
        if (! $videoId) {
            return null;
        }

        // YouTube video IDsは11文字の英数字、ハイフン、アンダースコア
        if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $videoId)) {
            return $videoId;
        }

        return null;
    }

    /**
     * YouTube Video IDが有効かどうかをチェック
     *
     * @param  string|null  $videoId  検証するVideo ID
     * @return bool 有効な場合はtrue
     */
    public static function isValidYouTubeVideoId(?string $videoId): bool
    {
        return self::validateYouTubeVideoId($videoId) !== null;
    }

    /**
     * Boolean値の文字列表現を正規化
     *
     * Axiosなどでクエリパラメータとして送信された場合、
     * boolean値は文字列（"true"/"false"）として送信されるため、
     * 適切にパースする必要がある
     *
     * @param  mixed  $value  パースする値
     * @param  bool  $default  パース失敗時のデフォルト値
     * @return bool パースされたboolean値
     */
    public static function parseBoolean(mixed $value, bool $default = false): bool
    {
        // null または 空文字の場合はデフォルト値を返す
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        $result = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $result ?? $default;
    }
}
