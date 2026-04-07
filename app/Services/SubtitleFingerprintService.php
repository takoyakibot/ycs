<?php

namespace App\Services;

use App\Models\SubtitleFingerprint;
use App\Models\TsItem;
use App\Models\VideoSubtitle;

class SubtitleFingerprintService
{
    /**
     * 動画の全ts_itemsに対してフィンガープリントを生成
     *
     * @return int 生成件数
     */
    public function generateFingerprintsForVideo(string $videoId): int
    {
        // 手動字幕を優先（kindが空 = 手動、'asr' = 自動生成）
        $subtitle = VideoSubtitle::where('video_id', $videoId)
            ->orderBy('kind', 'asc')
            ->first();

        if (! $subtitle) {
            return 0;
        }

        $tsItems = TsItem::where('video_id', $videoId)
            ->where('is_display', '1')
            ->whereNotNull('ts_num')
            ->get();

        $count = 0;
        foreach ($tsItems as $tsItem) {
            $fp = $this->generateFingerprint($tsItem, $subtitle);
            if ($fp) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 個別ts_itemのフィンガープリントを生成
     */
    public function generateFingerprint(TsItem $tsItem, VideoSubtitle $subtitle): ?SubtitleFingerprint
    {
        $segments = $subtitle->subtitle_data;
        if (empty($segments) || $tsItem->ts_num === null) {
            return null;
        }

        $durationSec = 30;
        $text = $this->extractSubtitleWindow($segments, (int) $tsItem->ts_num, $durationSec);

        if ($text === '') {
            return null;
        }

        $trigrams = self::generateTrigrams($text);
        if (empty($trigrams)) {
            return null;
        }

        return SubtitleFingerprint::updateOrCreate(
            ['ts_item_id' => $tsItem->id],
            [
                'video_id' => $tsItem->video_id,
                'start_sec' => (int) $tsItem->ts_num,
                'duration_sec' => $durationSec,
                'fingerprint_text' => $text,
                'trigrams' => $trigrams,
            ]
        );
    }

    /**
     * 字幕セグメントから指定時間範囲のテキストを切り出し
     */
    public function extractSubtitleWindow(array $segments, int $startSec, int $durationSec = 30): string
    {
        $windowStart = max(0, $startSec - 1);
        $windowEnd = $startSec + $durationSec;

        $texts = [];
        foreach ($segments as $segment) {
            $segStart = (float) ($segment['start'] ?? 0);
            $segEnd = $segStart + (float) ($segment['duration'] ?? 0);

            // セグメントがウィンドウと重なっていれば採用
            if ($segEnd > $windowStart && $segStart < $windowEnd) {
                $text = $segment['text'] ?? '';
                if ($text !== '') {
                    $texts[] = $text;
                }
            }
        }

        $joined = implode('', $texts);

        return self::normalizeForFingerprint($joined);
    }

    /**
     * フィンガープリント用のテキスト正規化
     * 句読点・記号除去、小文字化
     */
    public static function normalizeForFingerprint(string $text): string
    {
        // 小文字化
        $text = mb_strtolower($text, 'UTF-8');

        // スペース・句読点・記号を除去
        // Unicode カテゴリ: P(句読点), S(記号), Z(区切り) を除去
        $text = preg_replace('/[\p{P}\p{S}\p{Z}\s]+/u', '', $text);

        return $text;
    }

    /**
     * 文字レベルのトライグラム生成
     *
     * @return string[] ユニークなトライグラムの配列
     */
    public static function generateTrigrams(string $text): array
    {
        $chars = mb_str_split($text, 1, 'UTF-8');
        $len = count($chars);

        if ($len < 3) {
            return [];
        }

        $trigrams = [];
        for ($i = 0; $i <= $len - 3; $i++) {
            $trigrams[] = $chars[$i].$chars[$i + 1].$chars[$i + 2];
        }

        return array_values(array_unique($trigrams));
    }
}
