<?php

namespace App\Services;

use App\Models\SubtitleFingerprint;
use App\Models\TsItem;
use App\Models\VideoSubtitle;

class SubtitleFingerprintService
{
    /**
     * フィンガープリントの窓の長さ（秒）
     *
     * 歌い出し直後は前奏で歌声がなく、自動字幕が [音楽] だけになる区間が続く。
     * 30秒では前奏の長い楽曲で歌詞をまったく拾えないため60秒を採る。
     *
     * この値を変更した場合、既存のフィンガープリントは窓の長さが異なるため
     * 照合対象から外れる（SubtitleMatchingService が duration_sec の一致を要求する）。
     * 変更時は subtitle-fingerprints:generate で全件を再生成すること。
     */
    public const WINDOW_DURATION_SEC = 60;

    /**
     * 窓の開始を歌い出しの何秒手前から取るか
     */
    public const WINDOW_LEAD_SEC = 1;

    /**
     * フィンガープリント生成で優先する字幕の言語
     *
     * 同一動画に複数言語の字幕が保存されている場合（拡張の字幕パネルで
     * 言語を切り替えて取得すると普通に発生する）、歌唱内容を表す言語を
     * 選ばないとマッチングが機能しなくなるため、日本語を最優先する。
     */
    public const PREFERRED_LANGUAGE = 'ja';

    /**
     * フィンガープリントとして採用する最小トライグラム数
     *
     * トライグラムが少ないテキストはJaccard類似度が不安定になる。
     * 特に効果音アノテーションだけが並ぶ区間は種類数が数個まで縮退し、
     * 内容の異なる区間どうしが類似度1.0で一致してしまう。
     */
    public const MIN_TRIGRAM_COUNT = 20;

    /**
     * 動画の全ts_itemsに対してフィンガープリントを生成する。
     *
     * 生成対象から外れた既存のフィンガープリントは削除するため、
     * 呼び出し後はこの動画のフィンガープリントが現行条件のものだけになる。
     *
     * @return int 生成件数
     */
    public function generateFingerprintsForVideo(string $videoId): int
    {
        // 優先度: 日本語（ja / ja-JP等） > 手動字幕（kindが空。'asr' = 自動生成）。
        // 末尾のlanguage_code昇順は、同順位が並んだ場合でも選択結果を
        // DBの行順に依存させないための決定的なタイブレーク（#591）
        $subtitle = VideoSubtitle::where('video_id', $videoId)
            ->orderByRaw(
                'CASE WHEN language_code = ? THEN 0 WHEN language_code LIKE ? THEN 1 ELSE 2 END',
                [self::PREFERRED_LANGUAGE, self::PREFERRED_LANGUAGE.'-%']
            )
            ->orderBy('kind', 'asc')
            ->orderBy('language_code', 'asc')
            ->first();

        if (! $subtitle) {
            return 0;
        }

        $tsItems = TsItem::where('video_id', $videoId)
            ->where('is_display', '1')
            ->whereNotNull('ts_num')
            ->get();

        $generatedTsItemIds = [];
        foreach ($tsItems as $tsItem) {
            $fp = $this->generateFingerprint($tsItem, $subtitle);
            if ($fp) {
                $generatedTsItemIds[] = $tsItem->id;
            }
        }

        // 今回の条件で生成されなかったフィンガープリントを削除する。
        // 窓の長さの変更・ts_itemの非表示化・字幕の差し替えで古い行が残ると、
        // 現行条件の行と混在して照合結果が歪むため。
        SubtitleFingerprint::where('video_id', $videoId)
            ->whereNotIn('ts_item_id', $generatedTsItemIds)
            ->delete();

        return count($generatedTsItemIds);
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

        $durationSec = self::WINDOW_DURATION_SEC;
        $text = $this->extractSubtitleWindow($segments, (int) $tsItem->ts_num, $durationSec);

        if ($text === '') {
            return null;
        }

        $trigrams = self::generateTrigrams($text);
        if (count($trigrams) < self::MIN_TRIGRAM_COUNT) {
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
    public function extractSubtitleWindow(array $segments, int $startSec, ?int $durationSec = null): string
    {
        $durationSec ??= self::WINDOW_DURATION_SEC;

        $windowStart = max(0, $startSec - self::WINDOW_LEAD_SEC);
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
     * 効果音アノテーション除去、句読点・記号除去、小文字化
     */
    public static function normalizeForFingerprint(string $text): string
    {
        // 自動字幕の効果音アノテーション（[音楽] [拍手] [Music] など）を角括弧ごと除去する。
        // 記号除去だけでは角括弧が外れて「音楽」が本文として残り、前奏や間奏の窓が
        // 「音楽」の繰り返しだけになって、内容の異なる楽曲どうしが一致してしまう。
        // 歌詞側の誤除去を避けるため、括弧内の長さに上限を設ける。
        $text = preg_replace('/[\[［][^\[\]［］]{0,20}[\]］]/u', '', $text);

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
