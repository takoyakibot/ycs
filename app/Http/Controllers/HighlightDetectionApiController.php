<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManageAccessControl;
use App\Models\Archive;
use App\Services\Highlights\HighlightAiAnalyzer;
use App\Services\Highlights\HighlightCandidateExtractor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Chrome拡張からのハイライト検出リクエストを受け付けるエンドポイント。
 *
 * - ステートレス: 結果も入力もDBに保存しない（AI ゲートウェイ）
 * - 認証: Sanctum トークン + 管理者
 * - チャンネル単位のアクセス権チェックを行う（既存の字幕保存APIと同様）
 */
class HighlightDetectionApiController extends Controller
{
    use ManageAccessControl;

    public function __construct(
        private HighlightCandidateExtractor $extractor,
        private HighlightAiAnalyzer $analyzer,
    ) {}

    public function detect(Request $request)
    {
        // ペイロード上限（24時間配信を想定した現実的な上限）
        // - volumes: 2秒バケットで24h = 43200個
        // - subtitles: 平均2秒間隔 × 24h = 約43200個だが実用上は遥かに少ない
        // - chats: 24h × 平均60件/分 = 86400件、実態は1〜2万件程度
        $validated = $request->validate([
            'video_id' => ['required', 'string', 'size:11', 'regex:/^[A-Za-z0-9_-]{11}$/'],
            'duration' => ['required', 'numeric', 'min:1', 'max:86400'],
            'volumes' => ['present', 'array', 'max:50000'],
            'volumes.*' => ['numeric', 'min:0', 'max:1'],
            'subtitles' => ['present', 'array', 'max:10000'],
            'subtitles.*.start' => ['required', 'numeric', 'min:0', 'max:86400'],
            'subtitles.*.duration' => ['required', 'numeric', 'min:0', 'max:60'],
            'subtitles.*.text' => ['required', 'string', 'max:500'],
            'chats' => ['present', 'array', 'max:30000'],
            'chats.*.offsetMs' => ['required', 'integer', 'min:0', 'max:86400000'],
            'chats.*.message' => ['required', 'string', 'max:500'],
            'chats.*.isSuperchat' => ['nullable', 'boolean'],
        ]);

        $videoId = $validated['video_id'];

        $archive = Archive::where('video_id', $videoId)->first();
        if (! $archive) {
            return response()->json(['message' => '指定された動画はアーカイブに登録されていません'], 404);
        }

        $channel = $archive->channel;
        if (! $channel || ! $this->canAccessChannel($channel)) {
            return response()->json(['message' => 'このチャンネルへのアクセス権限がありません'], 403);
        }

        try {
            $candidates = $this->extractor->extract(
                duration: (float) $validated['duration'],
                volumes: $validated['volumes'],
                subtitles: $validated['subtitles'],
                chats: $validated['chats'],
            );

            $analyzed = $this->analyzer->analyze($candidates);

            Log::info('ハイライト検出完了', [
                'video_id' => $videoId,
                'duration' => $validated['duration'],
                'volumes_count' => count($validated['volumes']),
                'subtitles_count' => count($validated['subtitles']),
                'chats_count' => count($validated['chats']),
                'candidates_count' => count($analyzed),
            ]);

            return response()->json([
                'video_id' => $videoId,
                'candidates' => $analyzed,
            ]);
        } catch (\Throwable $e) {
            Log::error('ハイライト検出エラー', [
                'video_id' => $videoId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'ハイライト検出に失敗しました'], 500);
        }
    }
}
