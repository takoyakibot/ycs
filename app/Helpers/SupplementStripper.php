<?php

namespace App\Helpers;

/**
 * タイムスタンプから「曲名ではない補足」を除去する
 *
 * 例:
 *   気まぐれロマンティック / いきものがかり (エコーかけ忘れ)  → 気まぐれロマンティック / いきものがかり
 *   気まぐれロマンティック / いきものがかり 　エコーかけ忘れ   → 気まぐれロマンティック / いきものがかり
 *   気まぐれロマンティック（アンコール） / いきものがかり      → 気まぐれロマンティック / いきものがかり
 *   ♫気まぐれロマンティック / いきものがかり                  → 気まぐれロマンティック / いきものがかり
 *
 * 括弧やスペースを一律で潰すのではなく、config/ignore_dictionary.php の
 * supplement フラグ付きキーワードに当たった箇所だけを落とす。これにより "(Live)" や
 * "(feat. ○○)" のような曲名の一部としての括弧は保持される。
 *
 * TextNormalizer::normalize() の前段で使うことを想定している
 * （全角スペースや括弧の種類といった手掛かりが正規化で失われるため）。
 */
class SupplementStripper
{
    /** 先頭・末尾の装飾記号を除去 */
    public const RULE_SYMBOL = 'symbol';

    /** 補足キーワードを含む括弧を括弧ごと除去 */
    public const RULE_BRACKET = 'bracket';

    /** 区切り以降の補足ブロックを除去 */
    public const RULE_TRAILING = 'trailing';

    public const ALL_RULES = [
        self::RULE_SYMBOL,
        self::RULE_BRACKET,
        self::RULE_TRAILING,
    ];

    /**
     * 対応を見る括弧（開き => 閉じ）
     */
    private const BRACKET_PAIRS = [
        '(' => ')',
        '（' => '）',
        '[' => ']',
        '［' => '］',
        '【' => '】',
        '「' => '」',
        '『' => '』',
        '〔' => '〕',
        '〈' => '〉',
        '《' => '》',
        '{' => '}',
        '｛' => '｝',
    ];

    /**
     * 補足の前置きとみなす区切り
     * 全角スペース・タブ、または2連続以上の半角スペース
     */
    private const GAP_PATTERN = '/(?:[ \t]*[\x{3000}\t][ \t\x{3000}]*|[ ]{2,})/u';

    /**
     * RULE_TRAILING を適用する前提となる区切り文字
     *
     * 「曲名 / アーティスト」のように曲名とアーティストが明示的に区切られている
     * テキストに限定する。区切りが無い場合、後半が補足なのか曲名の一部なのかを
     * 判別できず、「YOASOBI　アンコール」のような並びを誤って削ってしまうため。
     */
    private const STRUCTURE_SEPARATOR_PATTERN = '/[\/／\-−－:：|｜]/u';

    /**
     * 正規化済みキーワードのキャッシュ（元の表記 => 正規化後）
     *
     * @var array<string, string>|null
     */
    private static ?array $normalizedKeywords = null;

    /**
     * 補足を除去したテキストを返す（タイムスタンプ全体を渡す想定）
     *
     * @param  string[]|null  $rules  適用するルール。null で全ルール
     */
    public static function strip(?string $text, ?array $rules = null): string
    {
        return self::analyze($text, $rules)['result'];
    }

    /**
     * TS分解のパーツ配列から補足を除去する
     *
     * パーツは区切り文字で分割された後なので、パーツ単体には区切りが含まれない
     * （例: 「いきものがかり 　エコーかけ忘れ」）。そのため RULE_TRAILING の
     * 区切り必須ガードを外して適用する。
     *
     * ただしパーツが1つしかない場合、元のテキストは区切り文字で分解されていない
     * ため、「YOASOBI　アンコール」の後半が補足なのか曲名の一部なのか判別できない。
     * この場合はガードを効かせて誤除去を防ぐ。
     *
     * パーツ単体を処理するメソッドは用意しない。パーツ数を見ないと上記の判別が
     * できず、曲名を削ってしまうため。
     *
     * @param  array<int, string>  $parts
     * @param  string[]|null  $rules  適用するルール。null で全ルール
     * @return array<int, array{
     *     result: string,
     *     hits: array<int, array{rule: string, keyword: ?string, removed: string}>
     * }>
     */
    public static function analyzeParts(array $parts, ?array $rules = null): array
    {
        $options = ['require_separator' => self::requiresSeparatorForParts($parts)];

        return array_map(
            fn ($part) => self::analyze((string) $part, $rules, $options),
            $parts
        );
    }

    /**
     * analyzeParts() の結果から補足除去後のパーツだけを返す
     *
     * @param  array<int, string>  $parts
     * @param  string[]|null  $rules  適用するルール。null で全ルール
     * @return array<int, string>
     */
    public static function stripParts(array $parts, ?array $rules = null): array
    {
        return array_map(
            fn (array $analysis) => $analysis['result'],
            self::analyzeParts($parts, $rules)
        );
    }

    /**
     * パーツ配列に対して RULE_TRAILING の区切り必須ガードが必要か
     *
     * @param  array<int, string>  $parts
     */
    public static function requiresSeparatorForParts(array $parts): bool
    {
        return count($parts) <= 1;
    }

    /**
     * 補足を除去し、どのルール・キーワードが効いたかも返す
     *
     * @param  string[]|null  $rules  適用するルール。null で全ルール
     * @param  array{require_separator?: bool}  $options
     *                                                    require_separator: RULE_TRAILING を「曲名/アーティストの区切りを含む
     *                                                    テキスト」に限定するか（既定 true）
     * @return array{
     *     result: string,
     *     hits: array<int, array{rule: string, keyword: ?string, removed: string}>
     * }
     */
    public static function analyze(?string $text, ?array $rules = null, array $options = []): array
    {
        $rules ??= self::ALL_RULES;
        $requireSeparator = $options['require_separator'] ?? true;

        if ($text === null || trim($text) === '') {
            return ['result' => '', 'hits' => []];
        }

        $hits = [];
        $result = $text;

        // 括弧 → 区切り以降 → 装飾記号 の順で処理する。
        // 装飾記号を最後にすることで、前段の除去で末尾に取り残された記号も掃除できる。
        if (in_array(self::RULE_BRACKET, $rules, true)) {
            $result = self::stripBracketedSupplements($result, $hits);
        }

        if (in_array(self::RULE_TRAILING, $rules, true)) {
            $result = self::stripTrailingSupplement($result, $hits, $requireSeparator);
        }

        if (in_array(self::RULE_SYMBOL, $rules, true)) {
            $result = self::stripDecorativeSymbols($result, $hits);
        }

        // 除去で生まれた連続スペースを1つに詰める。
        //
        // 括弧の除去はスペース1つに置き換えるため、括弧が中間にあると
        // 「曲名  / アーティスト」のように2連スペースが残る。この値は
        // cleaned_parts として画面に返り、--apply では parts / derived_title /
        // derived_artist に直接書き込まれる（どちらの経路も normalize() を通らない）。
        //
        // 圧縮は必ず stripTrailingSupplement() の後に置くこと。GAP_PATTERN が
        // 半角2連スペースを区切り以降ルールのギャップ境界として使っているため、
        // 前に置くと「曲名 / アーティスト（アンコール） 雑談」の後続補足が
        // 取り残される。
        //
        // 何も除去していないレコードには触らない。触ると、元から2連スペースが
        // あるだけのレコードが --apply の変更対象に入ってしまう。
        if ($hits !== []) {
            $result = preg_replace('/ {2,}/', ' ', $result) ?? $result;
        }

        $result = trim($result);

        // 全部消えてしまった場合は除去しなかったことにする（安全側に倒す）
        if ($result === '') {
            return ['result' => trim($text), 'hits' => []];
        }

        return ['result' => $result, 'hits' => $hits];
    }

    /**
     * 補足キーワードを含む括弧を、括弧ごと除去
     *
     * @param  array<int, array{rule: string, keyword: ?string, removed: string}>  $hits
     */
    private static function stripBracketedSupplements(string $text, array &$hits): string
    {
        foreach (self::BRACKET_PAIRS as $open => $close) {
            // 入れ子や閉じ忘れを巻き込まないよう、開き括弧・閉じ括弧を含まない中身のみ対象にする
            $inner = '[^'.preg_quote($open.$close, '/').']*';
            $pattern = '/'.preg_quote($open, '/').'('.$inner.')'.preg_quote($close, '/').'/u';

            $replaced = preg_replace_callback($pattern, function (array $matches) use (&$hits) {
                $keyword = self::findKeyword($matches[1]);

                if ($keyword === null) {
                    return $matches[0];
                }

                $hits[] = [
                    'rule' => self::RULE_BRACKET,
                    'keyword' => $keyword,
                    'removed' => $matches[0],
                ];

                // 前後が繋がらないようスペースに置き換える。
                // ここで2連スペースになることがあるが、analyze() の最後で1つに詰める
                // （このスペースは区切り以降ルールのギャップ境界として使われるため、
                // ここで詰めてはいけない）
                return ' ';
            }, $text);

            if ($replaced !== null) {
                $text = $replaced;
            }
        }

        return $text;
    }

    /**
     * 区切り（全角スペース等）以降に続く補足ブロックを除去
     *
     * 末尾のセグメントから前方向に見ていき、補足キーワードを含むセグメントが
     * 連続している範囲をまとめて落とす。
     *
     * @param  array<int, array{rule: string, keyword: ?string, removed: string}>  $hits
     * @param  bool  $requireSeparator  曲名/アーティストの区切りを含むテキストに限定するか
     */
    private static function stripTrailingSupplement(string $text, array &$hits, bool $requireSeparator = true): string
    {
        if ($requireSeparator && preg_match(self::STRUCTURE_SEPARATOR_PATTERN, $text) !== 1) {
            return $text;
        }

        $segments = preg_split(self::GAP_PATTERN, $text, -1, PREG_SPLIT_OFFSET_CAPTURE);

        if ($segments === false || count($segments) < 2) {
            return $text;
        }

        $cutIndex = null;
        $keyword = null;

        for ($i = count($segments) - 1; $i >= 1; $i--) {
            $found = self::findKeyword($segments[$i][0]);

            if ($found === null) {
                break;
            }

            $cutIndex = $i;
            $keyword = $found;
        }

        if ($cutIndex === null) {
            return $text;
        }

        // $segments[...][1] は PREG_SPLIT_OFFSET_CAPTURE のバイトオフセット
        $cutOffset = $segments[$cutIndex][1];
        $head = rtrim(substr($text, 0, $cutOffset));

        // 曲名側が消えてしまうなら除去しない
        if (trim($head) === '') {
            return $text;
        }

        $hits[] = [
            'rule' => self::RULE_TRAILING,
            'keyword' => $keyword,
            'removed' => substr($text, $cutOffset),
        ];

        return $head;
    }

    /**
     * 先頭・末尾の装飾記号を除去
     *
     * @param  array<int, array{rule: string, keyword: ?string, removed: string}>  $hits
     */
    private static function stripDecorativeSymbols(string $text, array &$hits): string
    {
        $symbols = array_filter(
            array_map('strval', IgnoreDictionary::symbols()),
            fn (string $symbol) => $symbol !== ''
        );

        if ($symbols === []) {
            return $text;
        }

        // 各記号の直後に異体字セレクタ（U+FE0F / U+FE0E）が付いた形も1単位として扱う。
        // YouTubeのタイムスタンプでは ▶️ や ❤️ のように「基底文字 + U+FE0F」の
        // 2コードポイントで書かれることが多く、基底文字だけを文字クラスに入れると
        // 先頭ではセレクタが孤児として残り、末尾では末尾文字がセレクタのため
        // 除去が一切効かない。
        //
        // 文字クラスに U+FE0F を足す形にはしないこと。辞書に無い絵文字（🏳️ など）
        // からセレクタだけを剥がしてしまい、装飾でないものを壊す。
        $alternatives = array_map(
            fn (string $symbol) => preg_quote($symbol, '/').'[\x{FE0E}\x{FE0F}]?',
            array_values($symbols)
        );
        $unit = '(?:[\s\x{3000}]|'.implode('|', $alternatives).')';
        $pattern = '/^'.$unit.'+|'.$unit.'+$/u';

        $replaced = preg_replace_callback($pattern, function (array $matches) use (&$hits) {
            // 空白だけの場合は trim 相当なのでヒット扱いしない
            if (preg_match('/\S/u', $matches[0]) === 1) {
                $hits[] = [
                    'rule' => self::RULE_SYMBOL,
                    'keyword' => null,
                    'removed' => $matches[0],
                ];
            }

            return '';
        }, $text);

        return $replaced ?? $text;
    }

    /**
     * テキストに含まれる補足キーワードを1つ返す（無ければ null）
     */
    private static function findKeyword(string $haystack): ?string
    {
        $normalizedHaystack = TextNormalizer::normalize($haystack);

        if ($normalizedHaystack === '') {
            return null;
        }

        foreach (self::normalizedKeywords() as $original => $normalized) {
            if (mb_strpos($normalizedHaystack, $normalized) !== false) {
                return $original;
            }
        }

        return null;
    }

    /**
     * 正規化済みキーワードを取得（元の表記 => 正規化後）
     *
     * @return array<string, string>
     */
    private static function normalizedKeywords(): array
    {
        if (self::$normalizedKeywords !== null) {
            return self::$normalizedKeywords;
        }

        $keywords = [];

        foreach (IgnoreDictionary::keywordsWithFlag('supplement') as $keyword) {
            $normalized = TextNormalizer::normalize($keyword);

            if ($normalized === '') {
                continue;
            }

            $keywords[$keyword] = $normalized;
        }

        return self::$normalizedKeywords = $keywords;
    }

    /**
     * キーワードキャッシュを破棄（テスト・設定変更時用）
     */
    public static function flushKeywordCache(): void
    {
        self::$normalizedKeywords = null;
    }

    /**
     * ルール名の配列を検証して返す
     *
     * @param  string[]  $rules
     * @return array{rules: string[], invalid: string[]}
     */
    public static function validateRules(array $rules): array
    {
        $valid = [];
        $invalid = [];

        foreach ($rules as $rule) {
            $rule = trim($rule);

            if ($rule === '') {
                continue;
            }

            if (in_array($rule, self::ALL_RULES, true)) {
                $valid[] = $rule;
            } else {
                $invalid[] = $rule;
            }
        }

        return ['rules' => array_values(array_unique($valid)), 'invalid' => $invalid];
    }
}
