<?php

namespace App\Helpers;

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
}
