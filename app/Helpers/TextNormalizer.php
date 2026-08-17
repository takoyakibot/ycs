<?php

namespace App\Helpers;

class TextNormalizer
{
    /**
     * 不正なUTF-8文字をサニタイズ
     *
     * YouTubeから取得したテキストに不正なUTF-8バイト列が含まれている場合、
     * MySQLへの挿入時にエラーが発生するため、事前に除去する。
     *
     * @param  string|null  $text  サニタイズ対象のテキスト
     * @return string サニタイズ後のテキスト
     */
    public static function sanitizeUtf8(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        // mb_convert_encodingで不正なバイト列を除去
        $sanitized = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        // それでも不正な場合はiconvで再処理
        if (! mb_check_encoding($sanitized, 'UTF-8')) {
            $sanitized = @iconv('UTF-8', 'UTF-8//IGNORE', $sanitized);
        }

        return $sanitized !== false ? $sanitized : '';
    }

    /**
     * タイムスタンプテキストを正規化
     *
     * 以下の処理を行います：
     * - 全角英数字を半角に変換（チルダは除く）
     * - 区切り文字（スラッシュ、ハイフン、コロン等）を統一
     * - チルダ系文字（～、〜）を半角チルダ（~）に統一
     * - 類似文字（引用符、括弧、中点等）を統一
     * - 空白文字を統一（全て半角スペースに）
     * - 先頭・末尾の空白をトリム
     * - 小文字に統一
     */
    public static function normalize(?string $text): string
    {
        if (empty($text)) {
            return '';
        }

        // 全角文字を半角に変換
        $text = mb_convert_kana($text, 'as', 'UTF-8');

        // 区切り文字を統一（スラッシュに統一）
        // 注意: 長音記号（ー）は前後にスペースがある場合のみ区切り文字として扱う
        $separators = ['/', '／', '-', '−', '－', ':', '：', '|', '｜'];
        foreach ($separators as $sep) {
            $text = str_replace($sep, '/', $text);
        }

        // 前後にスペースがある長音記号のみスラッシュに変換
        // 例: "アーティスト ー 楽曲名" → "アーティスト / 楽曲名"
        // 例: "ファーストテイク" → "ファーストテイク" (変換しない)
        $text = preg_replace('/\s+ー\s+/u', ' / ', $text);

        // チルダ系文字を半角チルダに統一
        // U+301C (WAVE DASH), U+FF5E (FULLWIDTH TILDE), U+223C (TILDE OPERATOR), U+02DC (SMALL TILDE)
        $text = str_replace(['～', '〜', '∼', '˜'], '~', $text);

        // 引用符を統一（Unicode escape sequence を使用してソースコード上の文字化けを防止）
        // ダブルクォート系 → " (U+0022)
        // U+201C: LEFT DOUBLE QUOTATION MARK, U+201D: RIGHT DOUBLE QUOTATION MARK,
        // U+201E: DOUBLE LOW-9 QUOTATION MARK, U+2033: DOUBLE PRIME,
        // U+301D: REVERSED DOUBLE PRIME QUOTATION MARK, U+301E: DOUBLE PRIME QUOTATION MARK,
        // U+FF02: FULLWIDTH QUOTATION MARK
        $text = str_replace(["\u{201C}", "\u{201D}", "\u{201E}", "\u{2033}", "\u{301D}", "\u{301E}", "\u{FF02}"], '"', $text);
        // シングルクォート系 → ' (U+0027)
        // U+2018: LEFT SINGLE QUOTATION MARK, U+2019: RIGHT SINGLE QUOTATION MARK,
        // U+201A: SINGLE LOW-9 QUOTATION MARK, U+2032: PRIME,
        // U+0060: GRAVE ACCENT, U+00B4: ACUTE ACCENT, U+FF07: FULLWIDTH APOSTROPHE
        $text = str_replace(["\u{2018}", "\u{2019}", "\u{201A}", "\u{2032}", '`', "\u{00B4}", "\u{FF07}"], "'", $text);

        // 括弧を統一
        // 全角丸括弧 → 半角（mb_convert_kanaでは変換されない）
        $text = str_replace(['（', '）'], ['(', ')'], $text);
        // 角括弧系 → []
        $text = str_replace(['［', '］', '【', '】', '〔', '〕', '〈', '〉', '《', '》'], ['[', ']', '[', ']', '[', ']', '[', ']', '[', ']'], $text);

        // 中点を統一 → ・
        $text = str_replace(['•', '·', '‧', '∙', '⋅', '･'], '・', $text);

        // 乗算記号を統一 → x
        $text = str_replace(['×', '✕', '✖', 'Ｘ', 'ｘ'], 'x', $text);

        // 連続する区切り文字を1つに
        $text = preg_replace('/\/+/', '/', $text);

        // ゼロ幅スペースなどの不可視文字を除去
        // U+200B: Zero Width Space
        // U+200C: Zero Width Non-Joiner
        // U+FEFF: Byte Order Mark (BOM)
        // 注意: U+200D (Zero Width Joiner) は絵文字シーケンスで使用されるため除去しない
        $text = preg_replace('/[\x{200B}\x{200C}\x{FEFF}]/u', '', $text);

        // 全角スペース・タブなどを半角スペースに統一
        $text = preg_replace('/[\s\x{3000}]+/u', ' ', $text);

        // 先頭・末尾の空白と区切り文字をトリム
        $text = trim($text, " \t\n\r\0\x0B/");

        // 小文字に統一
        $text = mb_strtolower($text, 'UTF-8');

        return $text;
    }

    /**
     * 2つのテキストが正規化後に一致するか判定
     */
    public static function equals(?string $text1, ?string $text2): bool
    {
        return static::normalize($text1) === static::normalize($text2);
    }

    /**
     * 先頭の全角スペース（および半角スペース）を除外し、不可視文字を除去
     */
    public static function trimFullwidthSpace(?string $text): string
    {
        if (empty($text)) {
            return '';
        }

        // ゼロ幅スペースなどの不可視文字を除去
        // 注意: U+200D (Zero Width Joiner) は絵文字シーケンスで使用されるため除去しない
        $text = preg_replace('/[\x{200B}\x{200C}\x{FEFF}]/u', '', $text);

        // 先頭の全角スペース（U+3000）と半角スペース、その他の空白文字を除去
        return preg_replace('/^[\s\x{3000}]+/u', '', $text);
    }

    /**
     * テキストから楽曲名とアーティスト名を抽出を試みる
     *
     * @return array{title: string, artist: ?string}
     */
    public static function extractSongInfo(?string $text): array
    {
        $normalized = static::normalize($text);

        // よくあるパターンで分割を試みる
        // パターン1: "アーティスト名 / 楽曲名"
        // パターン2: "楽曲名 / アーティスト名"
        // パターン3: "アーティスト名 - 楽曲名"

        $parts = explode('/', $normalized, 2);

        if (count($parts) === 2) {
            return [
                'artist' => trim($parts[0]),
                'title' => trim($parts[1]),
            ];
        }

        // 区切り文字がない場合は全体を楽曲名として扱う
        return [
            'title' => $normalized,
            'artist' => null,
        ];
    }

    /**
     * 区切り文字パターン（正規化前のテキスト用）
     * 類似の区切り文字を含む
     * 注意: 長音記号（ー U+30FC）は含まない（コーヒー等の誤分割を防ぐため）
     */
    private const SEPARATOR_PATTERN = '/[\/／\-−－:：|｜]/u';

    /**
     * 無視すべきキーワード（カバー関連など）
     */
    private const IGNORE_KEYWORDS = [
        'cover',
        'カバー',
        'mv',
        'music video',
        'オリジナル',
        'original',
        'full',
        'short',
        'shorts',
        'official',
        '公式',
        '歌ってみた',
        'utawaku',
        'vtuber',
        'vsinger',
    ];

    /**
     * テキストを区切り文字で分解（正規化前のテキスト用）
     *
     * @return array{
     *     parts: string[],
     *     separator_count: int,
     *     has_separators: bool,
     *     original: string
     * }
     */
    public static function splitBySeparators(?string $text): array
    {
        if (empty($text)) {
            return [
                'parts' => [],
                'separator_count' => 0,
                'has_separators' => false,
                'original' => '',
            ];
        }

        // 区切り文字で分割
        $parts = preg_split(self::SEPARATOR_PATTERN, $text, -1, PREG_SPLIT_NO_EMPTY);
        $parts = array_map('trim', $parts);
        $parts = array_filter($parts, fn ($part) => $part !== '');
        $parts = array_values($parts);

        $separatorCount = count($parts) > 0 ? count($parts) - 1 : 0;

        return [
            'parts' => $parts,
            'separator_count' => $separatorCount,
            'has_separators' => $separatorCount > 0,
            'original' => $text,
        ];
    }

    /**
     * 区切り文字を含むかどうかを判定
     */
    public static function hasSeparators(?string $text): bool
    {
        if (empty($text)) {
            return false;
        }

        return preg_match(self::SEPARATOR_PATTERN, $text) === 1;
    }

    /**
     * パーツが無視すべき（楽曲名・アーティスト名の候補にならない）かどうかを判定
     *
     * 無視キーワードと記号だけで構成されているパーツのみを無視対象とする。
     * 単純な部分一致で判定すると、キーワードを語の一部に含むアーティスト名
     * （例: "Official髭男dism" の "official"）まで無視してしまい、
     * アーティスト名が空の楽曲マスタが作られる原因になるため。
     */
    public static function isIgnorablePart(string $part): bool
    {
        $normalizedPart = self::normalize($part);

        if ($normalizedPart === '') {
            return false;
        }

        // 長いキーワードから除去する（"shorts" が "short" より先に除去されるように）
        $keywords = self::getIgnoreKeywords();
        usort($keywords, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        $remaining = $normalizedPart;
        $matched = false;
        foreach ($keywords as $keyword) {
            if ($keyword === '' || ! str_contains($remaining, $keyword)) {
                continue;
            }

            $remaining = str_replace($keyword, ' ', $remaining);
            $matched = true;
        }

        // キーワードを含まないパーツ（記号・絵文字のみなど）は無視対象にしない
        if (! $matched) {
            return false;
        }

        // 文字・数字が残っていなければ、キーワードと記号だけのパーツ
        return preg_match('/[\p{L}\p{N}]/u', $remaining) !== 1;
    }

    /**
     * 無視キーワードを正規化された形式で取得
     *
     * @return string[]
     */
    public static function getIgnoreKeywords(): array
    {
        return array_map(fn ($keyword) => self::normalize($keyword), self::IGNORE_KEYWORDS);
    }

    /**
     * パターンマッチで楽曲名/アーティスト名を推定
     *
     * @param  string[]  $parts  分解されたパーツ配列
     * @return array{
     *     title_index: int|null,
     *     artist_index: int|null,
     *     confidence: float,
     *     ignore_indices: int[]
     * }
     */
    public static function detectTitleArtistPattern(array $parts): array
    {
        if (count($parts) < 2) {
            return [
                'title_index' => count($parts) === 1 ? 0 : null,
                'artist_index' => null,
                'confidence' => count($parts) === 1 ? 1.0 : 0.0,
                'ignore_indices' => [],
            ];
        }

        $ignoreIndices = [];
        $candidateIndices = [];

        // 無視すべきパーツを特定
        foreach ($parts as $index => $part) {
            if (self::isIgnorablePart($part)) {
                $ignoreIndices[] = $index;
            } else {
                $candidateIndices[] = $index;
            }
        }

        // 候補が2つ以上ある場合
        if (count($candidateIndices) >= 2) {
            // 日本語文字（ひらがな・カタカナ・漢字）の割合を計算
            $firstIndex = $candidateIndices[0];
            $secondIndex = $candidateIndices[1];

            $firstJapaneseRatio = self::calculateJapaneseRatio($parts[$firstIndex]);
            $secondJapaneseRatio = self::calculateJapaneseRatio($parts[$secondIndex]);

            // 日本のVTuberカバー曲の傾向：「アーティスト名 / 楽曲名」が多い
            // 日本語名っぽい方をアーティスト、英語/カタカナ楽曲名っぽい方をタイトルと推定
            // ただし確信度は低めに設定

            $confidence = 0.5; // 基本確信度

            // 両方日本語が多い場合、または両方英語が多い場合は判定困難
            if (abs($firstJapaneseRatio - $secondJapaneseRatio) > 0.3) {
                $confidence = 0.7;
            }

            // パーツが2つだけの場合で、一方が明らかに人名っぽい場合
            if (count($candidateIndices) === 2) {
                // 最初のパーツがアーティスト、2番目が楽曲名と仮定（よくあるパターン）
                return [
                    'title_index' => $secondIndex,
                    'artist_index' => $firstIndex,
                    'confidence' => $confidence,
                    'ignore_indices' => $ignoreIndices,
                ];
            }

            // 3つ以上の候補がある場合は確信度を下げる
            return [
                'title_index' => null,
                'artist_index' => null,
                'confidence' => 0.3,
                'ignore_indices' => $ignoreIndices,
            ];
        }

        // 候補が1つだけの場合
        if (count($candidateIndices) === 1) {
            return [
                'title_index' => $candidateIndices[0],
                'artist_index' => null,
                'confidence' => 0.8,
                'ignore_indices' => $ignoreIndices,
            ];
        }

        // 候補がない場合（全て無視対象）
        return [
            'title_index' => null,
            'artist_index' => null,
            'confidence' => 0.0,
            'ignore_indices' => $ignoreIndices,
        ];
    }

    /**
     * テキスト内の日本語文字（ひらがな・カタカナ・漢字）の割合を計算
     */
    private static function calculateJapaneseRatio(string $text): float
    {
        if (empty($text)) {
            return 0.0;
        }

        // 日本語文字をカウント（ひらがな、カタカナ、漢字）
        preg_match_all('/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}\x{4E00}-\x{9FAF}]/u', $text, $matches);
        $japaneseCount = count($matches[0]);

        // 全文字数
        $totalCount = mb_strlen($text, 'UTF-8');

        if ($totalCount === 0) {
            return 0.0;
        }

        return $japaneseCount / $totalCount;
    }
}
