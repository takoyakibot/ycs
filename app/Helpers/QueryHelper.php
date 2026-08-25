<?php

namespace App\Helpers;

use Illuminate\Database\Eloquent\Builder;

class QueryHelper
{
    /**
     * あいまい検索でキーワードを区切る文字（文字・数字以外すべて）
     */
    private const FUZZY_TOKEN_PATTERN = '/[^\p{L}\p{Nd}]+/u';

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
     * 3. 先頭に連続する数値トークン（曲番号の "1." や "00:12:34" のようなタイムスタンプ）をすべて除去
     * 4. カバー曲表記などのノイズワードを除去（全て除去される場合は除去しない）
     *
     * 返却されるキーワードは常に分割後トークン集合の部分集合になる。つまりノイズ語や
     * 数値トークンを除去しても、それによってAND条件が緩くなる方向にしか働かないため、
     * 本来ヒットすべき楽曲を取りこぼすことはない（ヒット範囲が広がるだけ）。
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

        // タイムスタンプ形式を先頭から除去（normalize()がコロンをスラッシュに変換するため / で判定）
        $stripped = preg_replace('/^\d{1,2}\/\d{2}(\/\d{2})?\s*/u', '', $normalized);

        // 曲番号形式（1. 01. 等）を先頭から除去
        $stripped = preg_replace('/^\d{1,3}\.\s*/u', '', $stripped);

        // 全部除去されてしまった場合はフォールバック
        if (trim($stripped) === '') {
            $stripped = $normalized;
        }

        // 文字・数字以外を区切りとして分割
        // （"/" "-" ":" などの区切り文字、括弧、記号、絵文字、丸数字などを除去）
        $tokens = preg_split(self::FUZZY_TOKEN_PATTERN, $stripped, -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens === false) {
            return [];
        }

        $keywords = array_values(array_unique($tokens));

        // ノイズワード（cover、mv など）を除去
        $ignoreTokens = self::tokenizeIgnoreKeywords();
        $filtered = array_values(array_filter(
            $keywords,
            fn ($keyword) => ! in_array($keyword, $ignoreTokens, true)
        ));

        // 全てノイズワードだった場合は除去前のキーワードを使う
        return $filtered !== [] ? $filtered : $keywords;
    }

    /**
     * 無視キーワードを検索キーワードと同じ単位に分解する
     *
     * IGNORE_KEYWORDS には 'music video' のようにスペースを含む複合語がある。
     * 検索キーワードは単語単位に分割されているため、そのまま比較すると
     * 'music' がどのエントリとも一致せず検索語として残ってしまう。
     *
     * 分解の副作用として 'music' 単独もノイズ扱いになるが、
     * 全てがノイズだった場合は除去前のキーワードを使うフォールバックがあるため、
     * 「Music」だけで検索した場合は潰れない。
     *
     * @return string[]
     */
    private static function tokenizeIgnoreKeywords(): array
    {
        $tokens = [];

        foreach (TextNormalizer::getIgnoreKeywords() as $keyword) {
            $split = preg_split(self::FUZZY_TOKEN_PATTERN, $keyword, -1, PREG_SPLIT_NO_EMPTY);

            if ($split === false) {
                continue;
            }

            foreach ($split as $token) {
                $tokens[] = $token;
            }
        }

        return array_values(array_unique($tokens));
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
