<?php

namespace App\Services;

use App\Helpers\TextNormalizer;
use App\Models\ChannelExcludedWord;

class CoverSongTitleExtractorService
{
    /**
     * カッコ除去判定から除外する無視キーワード（#669）
     *
     * カッコ除去対象キーワードは TextNormalizer::getIgnoreKeywords() を
     * 単一の情報源とするが、こちらは「カッコ内の部分一致」で使うため、
     * 短い語・一般語の混入は無関係なカッコを丸ごと消す誤爆になる。
     *
     * - 'ver': "version" だけでなく "(silver bullet)" 等にも部分一致する
     * - 'video': "(videogame ...)" 等にも部分一致する
     * - 'utawaku' / 'vtuber' / 'vsinger': 動画タイトル向けの語で、
     *   従来の bracketKeywords に含まれていなかった挙動を維持する
     *
     * TextNormalizer 側は「パーツ全体がキーワードと記号だけか」の判定なので
     * これらを持っていても誤爆しない（棲み分けの詳細は
     * TextNormalizer::IGNORE_KEYWORDS のコメントを参照）。
     */
    private const BRACKET_KEYWORD_EXCLUSIONS = [
        'ver',
        'video',
        'utawaku',
        'vtuber',
        'vsinger',
    ];

    /**
     * カッコ除去対象キーワード（カッコ内にこれらが含まれていたら除去）
     *
     * TextNormalizer::getIgnoreKeywords() は正規化済み（小文字・半角）で返るため、
     * mb_strtolower したカッコ内容との部分一致にそのまま使える。
     *
     * @return string[]
     */
    private function bracketKeywords(): array
    {
        return array_values(array_diff(
            TextNormalizer::getIgnoreKeywords(),
            self::BRACKET_KEYWORD_EXCLUSIONS
        ));
    }

    /**
     * 除去するカバー系パターン（正規表現）
     * 順序が重要: より具体的なパターンを先に
     */
    private array $coverPatterns = [
        // "covered by ○○" または "cover by ○○" を末尾から除去
        '/\s*cover(?:ed)?\s+by\s+.+$/iu',
        // "sang by ○○" を末尾から除去
        '/\s*sang\s+by\s+.+$/iu',
        // "feat. ○○" や "ft. ○○" を末尾から除去（フィーチャリングは楽曲の一部の可能性もあるので慎重に）
        // '/\s*(?:feat\.?|ft\.?)\s+.+$/iu',
        // 日本語パターン
        '/\s*が歌う.+$/u',
        '/\s*が歌ってみた$/u',
        '/\s*を歌ってみた$/u',
        '/\s*歌ってみた$/u',
        '/\s*カバー$/u',
    ];

    /**
     * キャッシュ（同一リクエスト内での重複取得を防ぐ）
     */
    private array $excludedWordsCache = [];

    /**
     * カバー曲タイトルから楽曲名部分を抽出
     *
     * @param  string  $title  動画タイトル
     * @param  string  $channelId  チャンネルID（除外ワード取得用）
     * @return string 抽出された楽曲名
     */
    public function extract(string $title, string $channelId): string
    {
        $text = $title;

        // Step 1: カッコで囲まれた部分を除去（キーワード含む場合のみ）
        $text = $this->removeBracketedKeywords($text);

        // Step 2: カバー系キーワードとその後続テキストを除去
        $text = $this->removeCoverKeywords($text);

        // Step 3: チャンネル固有の除外ワードを除去
        $excludedWords = $this->getExcludedWords($channelId);
        $text = $this->removeExcludedWords($text, $excludedWords);

        // Step 4: クリーンアップ（前後の空白・区切り文字除去）
        $text = $this->cleanup($text);

        // 空になったら元タイトルを返す（抽出失敗のセーフガード）
        return $text !== '' ? $text : $title;
    }

    /**
     * カッコで囲まれたキーワード部分を除去
     * 対象: 【】「」『』()（）[]
     */
    private function removeBracketedKeywords(string $text): string
    {
        // 各種カッコのパターン
        $bracketPatterns = [
            '/【[^】]*】/u',
            '/「[^」]*」/u',
            '/『[^』]*』/u',
            '/\([^)]*\)/u',
            '/（[^）]*）/u',
            '/\[[^\]]*\]/u',
        ];

        foreach ($bracketPatterns as $pattern) {
            $text = preg_replace_callback($pattern, function ($matches) {
                $content = $matches[0];
                $lowerContent = mb_strtolower($content);

                // カッコ内にキーワードが含まれていたら除去
                foreach ($this->bracketKeywords() as $keyword) {
                    if (mb_strpos($lowerContent, mb_strtolower($keyword)) !== false) {
                        return '';
                    }
                }

                // キーワードが含まれていなければそのまま残す
                return $content;
            }, $text);
        }

        return $text;
    }

    /**
     * カバー系キーワードとその後続テキストを除去
     */
    private function removeCoverKeywords(string $text): string
    {
        foreach ($this->coverPatterns as $pattern) {
            $text = preg_replace($pattern, '', $text);
        }

        return $text;
    }

    /**
     * チャンネル固有の除外ワードを取得
     *
     * @return array<string> 除外ワードの配列
     */
    private function getExcludedWords(string $channelId): array
    {
        // キャッシュチェック
        if (isset($this->excludedWordsCache[$channelId])) {
            return $this->excludedWordsCache[$channelId];
        }

        $words = ChannelExcludedWord::where('channel_id', $channelId)
            ->pluck('word')
            ->toArray();

        $this->excludedWordsCache[$channelId] = $words;

        return $words;
    }

    /**
     * 除外ワードを除去（キャッシュを使用しない版、外部から除外ワードを渡す場合用）
     *
     * @param  array<string>  $excludedWords  除外ワードの配列
     */
    public function extractWithExcludedWords(string $title, array $excludedWords): string
    {
        $text = $title;

        // Step 1: カッコで囲まれた部分を除去
        $text = $this->removeBracketedKeywords($text);

        // Step 2: カバー系キーワードを除去
        $text = $this->removeCoverKeywords($text);

        // Step 3: 除外ワードを除去
        $text = $this->removeExcludedWords($text, $excludedWords);

        // Step 4: クリーンアップ
        $text = $this->cleanup($text);

        return $text !== '' ? $text : $title;
    }

    /**
     * 除外ワードをテキストから除去
     *
     * @param  array<string>  $excludedWords
     */
    private function removeExcludedWords(string $text, array $excludedWords): string
    {
        // 文字数降順でソート（包含関係がある場合に長い文字列を先に除去するため）
        usort($excludedWords, fn ($a, $b) => mb_strlen($b) - mb_strlen($a));

        foreach ($excludedWords as $word) {
            if ($word === '') {
                continue;
            }

            // 単語境界を考慮した除去（前後にスペースや区切り文字がある場合）
            // 完全一致ではなく、部分一致で除去
            $escapedWord = preg_quote($word, '/');
            $text = preg_replace('/\s*'.$escapedWord.'\s*/iu', ' ', $text);
        }

        return $text;
    }

    /**
     * テキストをクリーンアップ
     * - 連続するスペースを1つに
     * - 前後のスペース・区切り文字を除去
     */
    private function cleanup(string $text): string
    {
        // 連続するスペースを1つに
        $text = preg_replace('/\s+/u', ' ', $text);

        // 前後のスペースと区切り文字をトリム（UTF-8安全な正規表現を使用）
        // 注意: trim()はバイトレベルで動作するため、マルチバイト文字を引数に含めると
        // 文字の一部バイトが個別に除去され、UTF-8バイト列が壊れる
        $text = preg_replace('/^[\s\/／－―|｜-]+|[\s\/／－―|｜-]+$/u', '', $text);

        return $text;
    }

    /**
     * キャッシュをクリア（テスト用）
     */
    public function clearCache(): void
    {
        $this->excludedWordsCache = [];
    }
}
