<?php

namespace App\Services;

class ArchiveProcessorService
{
    /**
     * タイムスタンプの表示/非表示を更新
     *
     * comment_idごとの出現回数をカウントし、最も多いものを表示対象とする
     *
     * @param  array  $tsItems  タイムスタンプ配列（参照渡し）
     */
    public function updateDisplayTsItems(array &$tsItems): void
    {
        if (empty($tsItems)) {
            return;
        }

        // comment_idごとの出現回数をカウント
        $countByCommentId = [];
        foreach ($tsItems as &$item) {
            $item['is_display'] = '0';
            $commentId = $item['comment_id'];
            $countByCommentId[$commentId] = ($countByCommentId[$commentId] ?? 0) + 1;
        }

        // 最も多い comment_id を取得
        $maxCount = max($countByCommentId);
        // 1件しかない場合は初期表示なしとする
        if ($maxCount > 1) {
            $mostFrequentCommentIds = array_keys($countByCommentId, $maxCount, true);
            // タイムスタンプが同数の場合も考えられるが先勝ちとする
            if (count($mostFrequentCommentIds) > 0) {
                // is_display を更新
                foreach ($tsItems as &$item) {
                    $item['is_display'] = ($item['comment_id'] === $mostFrequentCommentIds[0]);
                }
            }
        }
    }
}
