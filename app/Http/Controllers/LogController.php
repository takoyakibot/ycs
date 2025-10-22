<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class LogController extends Controller
{
    /**
     * コンストラクタ - 認証済みユーザーのみアクセス可能
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * ログ一覧を表示
     */
    public function index()
    {
        $logPath = storage_path('logs');
        $logFiles = [];

        if (File::exists($logPath)) {
            $files = File::files($logPath);

            foreach ($files as $file) {
                if (pathinfo($file, PATHINFO_EXTENSION) === 'log') {
                    $logFiles[] = [
                        'name' => $file->getFilename(),
                        'path' => $file->getPathname(),
                        'size' => $this->formatBytes($file->getSize()),
                        'modified' => date('Y-m-d H:i:s', $file->getMTime()),
                    ];
                }
            }

            // 更新日時の降順でソート
            usort($logFiles, function ($a, $b) {
                return strtotime($b['modified']) - strtotime($a['modified']);
            });
        }

        return view('logs.index', compact('logFiles'));
    }

    /**
     * ログファイルの内容を表示
     */
    public function show(Request $request, $filename)
    {
        $logPath = storage_path('logs/'.$filename);

        // セキュリティチェック: ディレクトリトラバーサル攻撃を防止
        if (! File::exists($logPath) || ! str_starts_with(realpath($logPath), storage_path('logs'))) {
            abort(404);
        }

        $content = File::get($logPath);
        $lines = explode("\n", $content);

        // ページネーション用のパラメータ
        $page = (int) $request->get('page', 1);
        $perPage = 100; // 1ページあたりの行数
        $totalLines = count($lines);

        // 最新のログから表示するため、配列を逆順にする
        $lines = array_reverse($lines);

        // ページネーション
        $offset = ($page - 1) * $perPage;
        $currentPageLines = array_slice($lines, $offset, $perPage);

        // ログレベルごとに色分けするための解析
        $parsedLines = [];
        foreach ($currentPageLines as $lineNumber => $line) {
            $level = $this->extractLogLevel($line);
            $parsedLines[] = [
                'number' => $totalLines - $offset - $lineNumber,
                'content' => $line,
                'level' => $level,
                'timestamp' => $this->extractTimestamp($line),
            ];
        }

        $pagination = [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $totalLines,
            'last_page' => ceil($totalLines / $perPage),
            'has_prev' => $page > 1,
            'has_next' => $page < ceil($totalLines / $perPage),
        ];

        return view('logs.show', compact('filename', 'parsedLines', 'pagination'));
    }

    /**
     * ログファイルをダウンロード
     */
    public function download($filename)
    {
        $logPath = storage_path('logs/'.$filename);

        // セキュリティチェック
        if (! File::exists($logPath) || ! str_starts_with(realpath($logPath), storage_path('logs'))) {
            abort(404);
        }

        return response()->download($logPath);
    }

    /**
     * ログファイルを削除
     */
    public function delete($filename)
    {
        $logPath = storage_path('logs/'.$filename);

        // セキュリティチェック
        if (! File::exists($logPath) || ! str_starts_with(realpath($logPath), storage_path('logs'))) {
            abort(404);
        }

        // laravel.logは削除を禁止
        if ($filename === 'laravel.log') {
            return redirect()->route('logs.index')->with('error', 'laravel.logファイルは削除できません。');
        }

        File::delete($logPath);

        return redirect()->route('logs.index')->with('success', 'ログファイルを削除しました。');
    }

    /**
     * バイト数を人間が読みやすい形式に変換
     */
    private function formatBytes($size, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
            $size /= 1024;
        }

        return round($size, $precision).' '.$units[$i];
    }

    /**
     * ログ行からログレベルを抽出
     */
    private function extractLogLevel($line)
    {
        if (preg_match('/\.(ERROR|error)/', $line)) {
            return 'error';
        }
        if (preg_match('/\.(WARNING|warning)/', $line)) {
            return 'warning';
        }
        if (preg_match('/\.(INFO|info)/', $line)) {
            return 'info';
        }
        if (preg_match('/\.(DEBUG|debug)/', $line)) {
            return 'debug';
        }

        return 'default';
    }

    /**
     * ログ行からタイムスタンプを抽出
     */
    private function extractTimestamp($line)
    {
        if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
