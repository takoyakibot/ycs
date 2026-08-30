<?php

namespace App\Helpers;

class IgnoreDictionary
{
    /** @var array|null ファイル直接読み込み時のみキャッシュ */
    private static ?array $fileCache = null;

    /**
     * @return array<string, array{ignore_part: bool, bracket_keyword: bool, supplement: bool, fuzzy_stop: bool}>
     */
    public static function keywords(): array
    {
        return self::load('keywords', []);
    }

    /**
     * @return string[]
     */
    public static function symbols(): array
    {
        return self::load('symbols', []);
    }

    /**
     * 指定フラグが true のキーワードのキー（原文）を返す
     *
     * @return string[]
     */
    public static function keywordsWithFlag(string $flag): array
    {
        return array_keys(array_filter(
            self::keywords(),
            fn ($flags) => $flags[$flag] ?? false
        ));
    }

    public static function flush(): void
    {
        self::$fileCache = null;
    }

    /**
     * @param  mixed  $default
     * @return mixed
     */
    private static function load(string $key, $default)
    {
        if (function_exists('config')) {
            try {
                return (array) config("ignore_dictionary.{$key}", $default);
            } catch (\Throwable) {
                // Unit テストなどでサービスコンテナが利用できない場合
            }
        }

        return self::loadFromFile()[$key] ?? $default;
    }

    private static function loadFromFile(): array
    {
        if (self::$fileCache !== null) {
            return self::$fileCache;
        }

        $path = realpath(__DIR__.'/../../config/ignore_dictionary.php');
        if ($path === false || ! file_exists($path)) {
            return self::$fileCache = [];
        }

        return self::$fileCache = require $path;
    }
}
