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

    /**
     * カバー曲（歌ってみた）動画かどうかを判定
     *
     * タイトルに「歌ってみた」「cover」「カバー」が含まれる場合はカバー曲と判定
     *
     * @param  string  $title  動画タイトル
     * @return bool カバー曲の場合true
     */
    public function isCoverSong(string $title): bool
    {
        $lowerTitle = mb_strtolower($title);

        $keywords = ['歌ってみた', 'cover', 'カバー'];

        foreach ($keywords as $keyword) {
            if (mb_strpos($lowerTitle, mb_strtolower($keyword)) !== false) {
                return true;
            }
        }

        return false;
    }
}
