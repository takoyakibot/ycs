<?php

namespace App\Http\Controllers;

use App\Models\TimestampReport;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class TimestampReportController extends Controller
{
    /**
     * 報告管理画面を表示
     */
    public function manage(): View
    {
        return view('manage.reports');
    }

    /**
     * 報告を作成（ゲスト可、レートリミット付き）
     */
    public function store(Request $request): JsonResponse
    {
        // レートリミットチェック（1分間に5回まで）
        $key = 'timestamp-report:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json([
                'message' => "報告の送信制限中です。{$seconds}秒後に再度お試しください。",
            ], 429);
        }

        $validated = $request->validate([
            'ts_item_id' => 'required|string|max:26|exists:ts_items,id',
            'video_id' => 'required|string|max:11',
            'report_type' => 'required|string|max:20',
            'comment' => 'nullable|string|max:1000',
        ]);

        RateLimiter::hit($key, 60);

        $report = TimestampReport::create([
            'ts_item_id' => $validated['ts_item_id'],
            'video_id' => $validated['video_id'],
            'report_type' => $validated['report_type'],
            'comment' => $validated['comment'] ?? null,
            'reporter_ip' => $request->ip(),
        ]);

        return response()->json([
            'message' => '報告を受け付けました。ご協力ありがとうございます。',
            'report_id' => $report->id,
        ], 201);
    }

    /**
     * 管理画面用の報告一覧
     */
    public function index(Request $request): JsonResponse
    {
        $query = TimestampReport::with(['tsItem.archive'])
            ->orderBy('created_at', 'desc');

        // ステータスフィルター
        if ($request->has('status') && in_array($request->status, ['pending', 'resolved'])) {
            $query->where('status', $request->status);
        }

        $reports = $query->paginate(20);

        return response()->json($reports);
    }

    /**
     * 報告を解決済みにする
     */
    public function resolve(TimestampReport $report): JsonResponse
    {
        $report->markAsResolved();

        return response()->json([
            'message' => '報告を解決済みにしました。',
            'report' => $report->fresh(),
        ]);
    }

    /**
     * 報告の詳細を取得
     */
    public function show(TimestampReport $report): JsonResponse
    {
        $report->load(['tsItem.archive']);

        return response()->json($report);
    }
}
