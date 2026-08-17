<?php

namespace App\Helpers;

use Illuminate\Database\Eloquent\Builder;

class QueryHelper
{
    /**
     * LIKEクエリ用の文字列エスケープ
     *
     * SQLのLIKE句で使用される特殊文字（%, _, \）をエスケープする
     *
     * @param  string  $value  エスケープする文字列
     * @return string エスケープされた文字列
     */
    public static function escapeLikeString(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    /**
     * 検索文字列をスペースで分割してキーワード配列を取得
     *
     * 半角スペースと全角スペースの両方に対応
     *
     * @param  string  $search  検索文字列
     * @return array キーワード配列（空文字列の場合は空配列）
     */
    public static function splitSearchKeywords(string $search): array
    {
        $trimmed = trim($search);
        if ($trimmed === '') {
            return [];
        }

        $result = preg_split('/\s+|\x{3000}+/u', $trimmed, -1, PREG_SPLIT_NO_EMPTY);

        return $result === false ? [] : $result;
    }

    /**
     * あいまい検索用にキーワードを分割
     *
     * タイムスタンプのテキスト（例: "楽曲名 / アーティスト名"）をそのまま貼り付けても
     * 検索できるように、区切り文字をノイズとして扱ってキーワードに分解する。
     *
     * 処理内容:
     * 1. TextNormalizer::normalize() で正規化（全角→半角、区切り文字を "/" に統一、小文字化）
     * 2. 文字・数字以外（区切り文字、括弧、絵文字など）を境界として分割
     * 3. 先頭の曲番号（"1." など）を除去
     * 4. カバー曲表記などのノイズワードを除去（全て除去される場合は除去しない）
     *
     * 分割しすぎても各キーワードは元テキストの部分文字列のままなので、
     * 検索対象を取りこぼすことはない（ヒット範囲が広がるだけ）。
     *
     * 返却されるキーワードは正規化済みのため、正規化カラム
     * （normalized_title / normalized_artist など）と突き合わせて使用すること。
     *
     * @param  string  $search  検索文字列
     * @return array キーワード配列（空文字列の場合は空配列）
     */
    public static function splitFuzzyKeywords(string $search): array
    {
        $normalized = TextNormalizer::normalize($search);
        if ($normalized === '') {
            return [];
        }

        // 文字・数字以外を区切りとして分割
        // （"/" "-" ":" などの区切り文字、括弧、記号、絵文字、丸数字などを除去）
        $tokens = preg_split('/[^\p{L}\p{Nd}]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens === false) {
            return [];
        }

        // 先頭の曲番号（"1." "01" など）を除去
        // 他にキーワードがある場合のみ除去する（数字だけの検索を潰さないため）
        if (count($tokens) >= 2 && preg_match('/^\p{Nd}+$/u', $tokens[0]) === 1) {
            array_shift($tokens);
        }

        $keywords = array_values(array_unique($tokens));

        // ノイズワード（cover、mv など）を除去
        $ignoreKeywords = TextNormalizer::getIgnoreKeywords();
        $filtered = array_values(array_filter(
            $keywords,
            fn ($keyword) => ! in_array($keyword, $ignoreKeywords, true)
        ));

        // 全てノイズワードだった場合は除去前のキーワードを使う
        return $filtered !== [] ? $filtered : $keywords;
    }

    /**
     * AND検索条件をクエリに適用
     *
     * スペースで区切られた各キーワードがすべて含まれるレコードを検索
     *
     * @param  Builder  $query  クエリビルダー
     * @param  string  $search  検索文字列
     * @param  string  $column  検索対象のカラム名
     * @return Builder 条件が適用されたクエリビルダー
     */
    public static function applyAndSearch(Builder $query, string $search, string $column): Builder
    {
        $keywords = self::splitSearchKeywords($search);

        foreach ($keywords as $keyword) {
            $escaped = self::escapeLikeString($keyword);
            $query->where($column, 'like', "%{$escaped}%");
        }

        return $query;
    }

    /**
     * 複数カラムを対象としたAND検索条件をクエリに適用
     *
     * スペースで区切られた各キーワードが、いずれかのカラムに含まれるレコードを検索
     *
     * @param  Builder  $query  クエリビルダー
     * @param  string  $search  検索文字列
     * @param  string[]  $columns  検索対象のカラム名
     * @return Builder 条件が適用されたクエリビルダー
     */
    public static function applyAndSearchAny(Builder $query, string $search, array $columns): Builder
    {
        return self::applyKeywordsToColumns($query, self::splitSearchKeywords($search), $columns);
    }

    /**
     * あいまい検索条件をクエリに適用
     *
     * 区切り文字をノイズとして扱ってキーワードに分解し、
     * 各キーワードがいずれかのカラムに含まれるレコードを検索する。
     *
     * キーワードは正規化済みのため、$columns には正規化カラムを指定すること。
     *
     * @param  Builder  $query  クエリビルダー
     * @param  string  $search  検索文字列
     * @param  string[]  $columns  検索対象の正規化カラム名
     * @return Builder 条件が適用されたクエリビルダー
     */
    public static function applyFuzzySearch(Builder $query, string $search, array $columns): Builder
    {
        return self::applyKeywordsToColumns($query, self::splitFuzzyKeywords($search), $columns);
    }

    /**
     * キーワード配列を複数カラムに対するAND/OR条件として適用
     *
     * @param  Builder  $query  クエリビルダー
     * @param  string[]  $keywords  キーワード配列
     * @param  string[]  $columns  検索対象のカラム名
     * @return Builder 条件が適用されたクエリビルダー
     */
    private static function applyKeywordsToColumns(Builder $query, array $keywords, array $columns): Builder
    {
        if ($columns === []) {
            return $query;
        }

        foreach ($keywords as $keyword) {
            $escaped = self::escapeLikeString($keyword);
            $query->where(function ($q) use ($escaped, $columns) {
                foreach ($columns as $column) {
                    $q->orWhere($column, 'like', "%{$escaped}%");
                }
            });
        }

        return $query;
    }
}
