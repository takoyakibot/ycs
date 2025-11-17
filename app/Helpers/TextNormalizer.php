<?php

namespace App\Helpers;

class TextNormalizer
{
    /**
     * タイムスタンプテキストを正規化
     *
     * 以下の処理を行います：
     * - 全角英数字を半角に変換（チルダは除く）
     * - 区切り文字（スラッシュ、ハイフン、コロン等）を統一
     * - チルダ系文字（～、〜）を半角チルダ（~）に統一
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
        $separators = ['/', '／', '-', '−', '－', 'ー', ':', '：', '|', '｜'];
        foreach ($separators as $sep) {
            $text = str_replace($sep, '/', $text);
        }

        // チルダ系文字を半角チルダに統一
        $text = str_replace(['～', '〜'], '~', $text);

        // 連続する区切り文字を1つに
        $text = preg_replace('/\/+/', '/', $text);

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
     * 先頭の全角スペース（および半角スペース）を除外
     */
    public static function trimFullwidthSpace(?string $text): string
    {
        if (empty($text)) {
            return '';
        }

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
}
