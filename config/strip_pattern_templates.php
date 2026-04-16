<?php

return [
    [
        'label' => '隅付き括弧【】の中身を除去',
        'pattern' => '/【.*?】/u',
        'is_regex' => true,
    ],
    [
        'label' => '全角丸括弧（）の中身を除去',
        'pattern' => '/（.*?）/u',
        'is_regex' => true,
    ],
    [
        'label' => '半角丸括弧()の中身を除去',
        'pattern' => '/\(.*?\)/u',
        'is_regex' => true,
    ],
    [
        'label' => '音符系絵文字を除去',
        'pattern' => '/[\x{1F3B5}\x{1F3B6}\x{1F3B4}\x{1F3B7}-\x{1F3BB}\x{266A}\x{266B}]/u',
        'is_regex' => true,
    ],
    [
        'label' => '再生ボタン系記号を除去',
        'pattern' => '/[\x{25B6}\x{25BA}\x{23E9}\x{23EF}\x{25C0}]/u',
        'is_regex' => true,
    ],
    [
        'label' => '装飾記号（星・キラキラ等）を除去',
        'pattern' => '/[\x{2728}\x{2B50}\x{1F31F}\x{1F4AB}\x{2764}\x{1F496}\x{1F497}\x{1F499}\x{1F49A}\x{1F49B}\x{1F49C}]/u',
        'is_regex' => true,
    ],
];
