<?php

/*
|--------------------------------------------------------------------------
| 無視キーワード統合辞書
|--------------------------------------------------------------------------
|
| 「曲名ではない語」を判定する辞書を一元管理する。各キーワードに用途フラグを
| 持たせ、各サービスは自分に該当するフラグでフィルタして使う。
|
| 用途フラグ:
| - ignore_part: パーツ全体がノイズか判定（TextNormalizer::isIgnorablePart）
| - bracket_keyword: カバー曲抽出時のカッコ除去対象（CoverSongTitleExtractorService）
| - supplement: 括弧内・区切り以降の補足除去（SupplementStripper）
| - fuzzy_stop: あいまい検索のストップワード（QueryHelper::splitFuzzyKeywords）
|
| 語を追加するときは、どの経路に効かせたいかを確認して該当するフラグだけを
| true にすること。
|
| 注意事項:
| - ignore_part に短い語（by, ed 等）を入れると isIgnorablePart() の部分一致で
|   誤爆する。そういった語は fuzzy_stop のみにする
| - bracket_keyword に短い語（ver, video 等）を入れると "(silver bullet)" 等に
|   部分一致して無関係なカッコを丸ごと消す。BRACKET_KEYWORD_EXCLUSIONS で除外中
| - supplement に曲名になりうる語（full, short 等）は入れない
| - 単体で曲名になりうる語を ignore_part に入れると、候補が1つに減り
|   detectTitleArtistPattern() が confidence 0.8 を返して自動確定するため
|   アーティストが空の楽曲マスタが作られる危険がある
|
| チャンネルごとの除去パターン（config/strip_pattern_templates.php ＋
| channel_strip_patterns テーブル）は管理画面で編集する別系統。
|
| 影響の確認:
| - supplement 系: `php artisan ts-decompositions:clean-supplements`（dry-run がデフォルト）
| - ignore_part / bracket_keyword 系: TextNormalizer / CoverSongTitleExtractorService のテスト
| - fuzzy_stop 系: QueryHelper のテスト
|
*/

return [
    'keywords' => [
        // --- ignore_part + bracket_keyword（パーツ全体のノイズ判定 ＋ カッコ除去）---

        'cover' => ['ignore_part' => true, 'bracket_keyword' => true, 'supplement' => false, 'fuzzy_stop' => false],
        'カバー' => ['ignore_part' => true, 'bracket_keyword' => true, 'supplement' => false, 'fuzzy_stop' => false],
        'mv' => ['ignore_part' => true, 'bracket_keyword' => true, 'supplement' => false, 'fuzzy_stop' => false],
        'music video' => ['ignore_part' => true, 'bracket_keyword' => true, 'supplement' => false, 'fuzzy_stop' => false],
        'オリジナル' => ['ignore_part' => true, 'bracket_keyword' => true, 'supplement' => false, 'fuzzy_stop' => false],
        'original' => ['ignore_part' => true, 'bracket_keyword' => true, 'supplement' => false, 'fuzzy_stop' => false],
        'full' => ['ignore_part' => true, 'bracket_keyword' => true, 'supplement' => false, 'fuzzy_stop' => false],
        'short' => ['ignore_part' => true, 'bracket_keyword' => true, 'supplement' => false, 'fuzzy_stop' => false],
        'shorts' => ['ignore_part' => true, 'bracket_keyword' => true, 'supplement' => false, 'fuzzy_stop' => false],
        'official' => ['ignore_part' => true, 'bracket_keyword' => true, 'supplement' => false, 'fuzzy_stop' => false],
        '公式' => ['ignore_part' => true, 'bracket_keyword' => true, 'supplement' => false, 'fuzzy_stop' => false],
        '歌ってみた' => ['ignore_part' => true, 'bracket_keyword' => true, 'supplement' => false, 'fuzzy_stop' => false],

        // --- ignore_part のみ（bracket_keyword ではカッコ部分一致で誤爆するため除外）---

        'video' => ['ignore_part' => true, 'bracket_keyword' => false, 'supplement' => false, 'fuzzy_stop' => false],
        'ver' => ['ignore_part' => true, 'bracket_keyword' => false, 'supplement' => false, 'fuzzy_stop' => false],
        'utawaku' => ['ignore_part' => true, 'bracket_keyword' => false, 'supplement' => false, 'fuzzy_stop' => false],
        'vtuber' => ['ignore_part' => true, 'bracket_keyword' => false, 'supplement' => false, 'fuzzy_stop' => false],
        'vsinger' => ['ignore_part' => true, 'bracket_keyword' => false, 'supplement' => false, 'fuzzy_stop' => false],

        // --- supplement のみ（括弧内・区切り以降の補足除去）---

        'エコー' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => true, 'fuzzy_stop' => false],
        'かけ忘れ' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => true, 'fuzzy_stop' => false],
        'かけ間違い' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => true, 'fuzzy_stop' => false],
        '音量' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => true, 'fuzzy_stop' => false],
        'ボリューム' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => true, 'fuzzy_stop' => false],
        'マイク' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => true, 'fuzzy_stop' => false],
        '音ズレ' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => true, 'fuzzy_stop' => false],
        'ノイズ' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => true, 'fuzzy_stop' => false],
        'ハウリング' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => true, 'fuzzy_stop' => false],
        '無音' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => true, 'fuzzy_stop' => false],
        'bgm' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => true, 'fuzzy_stop' => false],
        'アンコール' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => true, 'fuzzy_stop' => false],
        'encore' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => true, 'fuzzy_stop' => false],
        'リクエスト' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => true, 'fuzzy_stop' => false],
        'リハ' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => true, 'fuzzy_stop' => false],
        '雑談' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => true, 'fuzzy_stop' => false],
        'ネタバレ' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => true, 'fuzzy_stop' => false],
        'ワンコーラス' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => true, 'fuzzy_stop' => false],
        '1番のみ' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => true, 'fuzzy_stop' => false],
        '2番のみ' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => true, 'fuzzy_stop' => false],
        'サビのみ' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => true, 'fuzzy_stop' => false],
        '途中から' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => true, 'fuzzy_stop' => false],
        '途中まで' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => true, 'fuzzy_stop' => false],
        '歌詞間違い' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => true, 'fuzzy_stop' => false],
        '歌詞ミス' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => true, 'fuzzy_stop' => false],

        // --- fuzzy_stop のみ（あいまい検索のストップワード）---

        'by' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => false, 'fuzzy_stop' => true],
        'feat' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => false, 'fuzzy_stop' => true],
        'ft' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => false, 'fuzzy_stop' => true],
        'featuring' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => false, 'fuzzy_stop' => true],
        'with' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => false, 'fuzzy_stop' => true],
        'op' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => false, 'fuzzy_stop' => true],
        'ed' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => false, 'fuzzy_stop' => true],
        'ost' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => false, 'fuzzy_stop' => true],
        'inst' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => false, 'fuzzy_stop' => true],
        'インスト' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => false, 'fuzzy_stop' => true],
        'instrumental' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => false, 'fuzzy_stop' => true],
        'フル' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => false, 'fuzzy_stop' => true],
        'TVアニメ' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => false, 'fuzzy_stop' => true],
        'アニメ' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => false, 'fuzzy_stop' => true],
        'remix' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => false, 'fuzzy_stop' => true],
        'リミックス' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => false, 'fuzzy_stop' => true],
        'acoustic' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => false, 'fuzzy_stop' => true],
        'アコースティック' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => false, 'fuzzy_stop' => true],
        'live' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => false, 'fuzzy_stop' => true],
        'ライブ' => ['ignore_part' => false, 'bracket_keyword' => false, 'supplement' => false, 'fuzzy_stop' => true],
    ],

    /*
    |--------------------------------------------------------------------------
    | 装飾記号
    |--------------------------------------------------------------------------
    |
    | テキストの先頭・末尾に付いているだけの飾り。SupplementStripper が無条件で除去する。
    | 曲名の内部には手を付けないので、記号を含む曲名は壊れない。
    |
    | チャンネルごとの除去パターン（strip_pattern_templates.php）は全体から除去する別系統。
    | 記号の重複は意図的（適用範囲が異なる）。
    |
    */
    'symbols' => [
        // 音符・音楽系
        '♪', '♫', '♬', '♩', '🎵', '🎶', '🎼', '🎤', '🎙', '🎧',

        // 星・キラキラ系
        '★', '☆', '✦', '✧', '✩', '✰', '⭐', '✨', '🌟', '💫',

        // 図形・記号系
        '※', '▶', '▷', '►', '▸', '◆', '◇', '■', '□', '●', '○', '◎',

        // ハート系
        '♡', '♥', '❤', '💖', '💕', '💗',

        // 矢印・箇条書き系
        '→', '⇒', '‣', '・', '･',
    ],
];
