<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\File;
use League\CommonMark\CommonMarkConverter;

class MarkdownController extends Controller
{
    public function show()
    {
        $terms = $this->markdownToHtml('lang/ja/terms.md');
        $privacyPolicy = $this->markdownToHtml('lang/ja/privacyPolicy.md');

        // ビューに渡して表示
        return view('legal.markdown', ['terms' => $terms, 'privacyPolicy' => $privacyPolicy]);
    }

    private function markdownToHtml($path)
    {
        $filePath = resource_path($path);

        // ファイルの存在確認
        if (! File::exists($filePath)) {
            abort(404, 'Markdown file not found.');
        }

        // Markdownファイルの読み込み
        $markdownContent = File::get($filePath);

        // MarkdownをHTMLに変換
        $converter = new CommonMarkConverter(['html_input' => 'strip']);

        return $converter->convertToHtml($markdownContent);
    }
}
