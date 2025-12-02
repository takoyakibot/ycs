<?php

namespace App\Helpers;

class CharacterCategorizer
{
    /**
     * 五十音行のマッピング（ひらがな・カタカナ）
     */
    private const KANA_MAP = [
        'あ' => ['あ', 'い', 'う', 'え', 'お', 'ア', 'イ', 'ウ', 'エ', 'オ'],
        'か' => ['か', 'き', 'く', 'け', 'こ', 'が', 'ぎ', 'ぐ', 'げ', 'ご',
            'カ', 'キ', 'ク', 'ケ', 'コ', 'ガ', 'ギ', 'グ', 'ゲ', 'ゴ'],
        'さ' => ['さ', 'し', 'す', 'せ', 'そ', 'ざ', 'じ', 'ず', 'ぜ', 'ぞ',
            'サ', 'シ', 'ス', 'セ', 'ソ', 'ザ', 'ジ', 'ズ', 'ゼ', 'ゾ'],
        'た' => ['た', 'ち', 'つ', 'て', 'と', 'だ', 'ぢ', 'づ', 'で', 'ど',
            'タ', 'チ', 'ツ', 'テ', 'ト', 'ダ', 'ヂ', 'ヅ', 'デ', 'ド'],
        'な' => ['な', 'に', 'ぬ', 'ね', 'の', 'ナ', 'ニ', 'ヌ', 'ネ', 'ノ'],
        'は' => ['は', 'ひ', 'ふ', 'へ', 'ほ', 'ば', 'び', 'ぶ', 'べ', 'ぼ',
            'ぱ', 'ぴ', 'ぷ', 'ぺ', 'ぽ',
            'ハ', 'ヒ', 'フ', 'ヘ', 'ホ', 'バ', 'ビ', 'ブ', 'ベ', 'ボ',
            'パ', 'ピ', 'プ', 'ペ', 'ポ'],
        'ま' => ['ま', 'み', 'む', 'め', 'も', 'マ', 'ミ', 'ム', 'メ', 'モ'],
        'や' => ['や', 'ゆ', 'よ', 'ヤ', 'ユ', 'ヨ'],
        'ら' => ['ら', 'り', 'る', 'れ', 'ろ', 'ラ', 'リ', 'ル', 'レ', 'ロ'],
        'わ' => ['わ', 'を', 'ん', 'ワ', 'ヲ', 'ン'],
    ];

    /**
     * タイトルから頭文字カテゴリを取得
     *
     * @param  string|null  $title  タイトル文字列
     * @return string|null カテゴリ名、または空の場合はnull
     */
    public static function getCategory(?string $title): ?string
    {
        if (empty($title)) {
            return null;
        }

        $firstChar = mb_substr($title, 0, 1, 'UTF-8');
        $firstChar = mb_strtoupper($firstChar, 'UTF-8');

        return self::categorize($firstChar);
    }

    /**
     * 頭文字をカテゴリに分類
     *
     * @param  string  $char  頭文字
     * @return string カテゴリ名
     */
    public static function categorize(string $char): string
    {
        $upperChar = strtoupper($char);

        // アルファベット（ABCDE, FGHIJ, KLMNO, PQRST, UVWXYZ）
        if (preg_match('/^[A-E]$/', $upperChar)) {
            return 'ABCDE';
        }
        if (preg_match('/^[F-J]$/', $upperChar)) {
            return 'FGHIJ';
        }
        if (preg_match('/^[K-O]$/', $upperChar)) {
            return 'KLMNO';
        }
        if (preg_match('/^[P-T]$/', $upperChar)) {
            return 'PQRST';
        }
        if (preg_match('/^[U-Z]$/', $upperChar)) {
            return 'UVWXYZ';
        }

        // ひらがな・カタカナ（五十音行に分類）
        foreach (self::KANA_MAP as $category => $chars) {
            if (in_array($char, $chars)) {
                return $category;
            }
        }

        // 数字（0-9）
        if (preg_match('/^[0-9]$/', $char)) {
            return '0-9';
        }

        // その他（記号など）
        return 'その他';
    }

    /**
     * 利用可能なインデックスカテゴリの一覧
     *
     * @return array<string>
     */
    public static function getAllCategories(): array
    {
        return [
            'ABCDE', 'FGHIJ', 'KLMNO', 'PQRST', 'UVWXYZ',
            '0-9', 'あ', 'か', 'さ', 'た', 'な', 'は', 'ま', 'や', 'ら', 'わ', 'その他',
        ];
    }

    /**
     * カテゴリに属する頭文字のリストを取得（SQL WHERE句用）
     *
     * @param  string  $category  カテゴリ名
     * @return array<string> 頭文字のリスト
     */
    public static function getCharsForCategory(string $category): array
    {
        // アルファベットカテゴリ
        $alphabetMap = [
            'ABCDE' => ['A', 'B', 'C', 'D', 'E', 'a', 'b', 'c', 'd', 'e'],
            'FGHIJ' => ['F', 'G', 'H', 'I', 'J', 'f', 'g', 'h', 'i', 'j'],
            'KLMNO' => ['K', 'L', 'M', 'N', 'O', 'k', 'l', 'm', 'n', 'o'],
            'PQRST' => ['P', 'Q', 'R', 'S', 'T', 'p', 'q', 'r', 's', 't'],
            'UVWXYZ' => ['U', 'V', 'W', 'X', 'Y', 'Z', 'u', 'v', 'w', 'x', 'y', 'z'],
        ];

        if (isset($alphabetMap[$category])) {
            return $alphabetMap[$category];
        }

        // 数字カテゴリ
        if ($category === '0-9') {
            return ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        }

        // 五十音カテゴリ
        if (isset(self::KANA_MAP[$category])) {
            return self::KANA_MAP[$category];
        }

        // その他（空配列を返す、特殊処理が必要）
        return [];
    }

    /**
     * 「その他」カテゴリかどうかを判定
     *
     * @param  string  $category  カテゴリ名
     */
    public static function isOtherCategory(string $category): bool
    {
        return $category === 'その他';
    }
}
