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

        return preg_split('/\s+|\x{3000}+/u', $trimmed, -1, PREG_SPLIT_NO_EMPTY);
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
}
