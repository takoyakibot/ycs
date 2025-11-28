<?php

namespace App\Services;

class VideoAnalyzerService
{
    /**
     * 動画タイトルから歌枠かどうかを判定
     *
     * @param  string  $title  動画タイトル
     * @return bool 歌枠の場合true
     */
    public function isSingingStream(string $title): bool
    {
        // 特定の歌枠タイトルに含まれるかを判定する
        $keywords = ['singing stream', '歌枠', 'カラオケ', 'karaoke'];
        foreach ($keywords as $keyword) {
            if (str_contains(strtolower($title), $keyword)) {
                return true;
            }
        }

        return false;
    }
}
