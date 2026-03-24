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
            'video_id' => ['required', 'string', 'size:11', 'regex:/^[A-Za-z0-9_-]{11}$/'],
            'ts_text' => 'required|string|max:20',
            'ts_num' => 'required|integer|min:0',
            'report_type' => 'required|string|max:20',
            'comment' => 'nullable|string|max:1000',
        ]);

        // ts_itemが存在するか確認
        $tsItemExists = \App\Models\TsItem::where('video_id', $validated['video_id'])
            ->where('ts_text', $validated['ts_text'])
            ->where('ts_num', $validated['ts_num'])
            ->exists();

        if (! $tsItemExists) {
            return response()->json([
                'message' => '報告対象のタイムスタンプが見つかりません。',
            ], 422);
        }

        RateLimiter::hit($key, 60);

        $report = TimestampReport::create([
            'video_id' => $validated['video_id'],
            'ts_text' => $validated['ts_text'],
            'ts_num' => $validated['ts_num'],
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
        $query = TimestampReport::orderBy('created_at', 'desc');

        // ステータスフィルター
        if ($request->has('status') && in_array($request->status, ['pending', 'resolved'])) {
            $query->where('status', $request->status);
        }

        $reports = $query->paginate(20);

        // N+1回避: 報告に対応するts_itemsをまとめて取得
        $this->loadTsItemsForReports($reports->getCollection());

        return response()->json($reports);
    }

    /**
     * 報告のコレクションに対応するts_itemsを一括で読み込む（N+1回避）
     */
    private function loadTsItemsForReports(\Illuminate\Support\Collection $reports): void
    {
        if ($reports->isEmpty()) {
            return;
        }

        // 報告に対応するts_itemsを一括取得
        $conditions = $reports->map(fn ($report) => [
            'video_id' => $report->video_id,
            'ts_text' => $report->ts_text,
            'ts_num' => $report->ts_num,
        ])->toArray();

        $tsItems = \App\Models\TsItem::with('archive')
            ->where(function ($query) use ($conditions) {
                foreach ($conditions as $cond) {
                    $query->orWhere(function ($q) use ($cond) {
                        $q->where('video_id', $cond['video_id'])
                            ->where('ts_text', $cond['ts_text'])
                            ->where('ts_num', $cond['ts_num']);
                    });
                }
            })
            ->get()
            ->keyBy(fn ($item) => $item->video_id.'|'.$item->ts_text.'|'.$item->ts_num);

        // 各報告にts_itemをセット
        $reports->transform(function ($report) use ($tsItems) {
            $key = $report->video_id.'|'.$report->ts_text.'|'.$report->ts_num;
            $report->ts_item = $tsItems->get($key);

            return $report;
        });
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
        // 対応するts_itemを取得
        $report->ts_item = $report->tsItem;
        if ($report->ts_item) {
            $report->ts_item->load('archive');
        }

        return response()->json($report);
    }
}
