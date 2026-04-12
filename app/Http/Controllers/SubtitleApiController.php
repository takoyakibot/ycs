<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManageAccessControl;
use App\Models\Archive;
use App\Models\VideoSubtitle;
use App\Services\SubtitleFingerprintService;
use App\Services\SubtitleMatchingService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class SubtitleApiController extends Controller
{
    use ManageAccessControl;

    public function __construct(
        private SubtitleFingerprintService $fingerprintService,
        private SubtitleMatchingService $matchingService,
    ) {}

    /**
     * 字幕データを保存（Chrome拡張から自動送信）
     * UPSERT: 同じvideo_id + language_code + kindなら更新
     */
    public function store(Request $request)
    {
        $request->validate([
            'video_id' => ['required', 'string', 'size:11', 'regex:/^[A-Za-z0-9_-]{11}$/'],
            'language_code' => ['required', 'string', 'regex:/^[a-zA-Z]{2,3}(-[a-zA-Z0-9]+)*$/', 'max:20'],
            'kind' => ['present', 'nullable', 'string', Rule::in(['asr', ''])],
            'subtitles' => ['required', 'array', 'min:1', 'max:10000'],
            'subtitles.*.start' => ['required', 'numeric', 'min:0', 'max:86400'],
            'subtitles.*.duration' => ['required', 'numeric', 'min:0', 'max:60'],
            'subtitles.*.text' => ['required', 'string', 'max:500'],
        ]);

        $videoId = $request->input('video_id');
        $languageCode = $request->input('language_code');
        $kind = $request->input('kind') ?? '';
        $subtitles = $request->input('subtitles');

        // アーカイブの存在確認とアクセス権チェック
        $archive = Archive::where('video_id', $videoId)->first();
        if (! $archive) {
            return response()->json(['message' => '指定された動画はアーカイブに登録されていません'], 404);
        }

        $channel = $archive->channel;
        if (! $channel || ! $this->canAccessChannel($channel)) {
            return response()->json(['message' => 'このチャンネルへのアクセス権限がありません'], 403);
        }

        try {
            $subtitle = VideoSubtitle::updateOrCreate(
                [
                    'video_id' => $videoId,
                    'language_code' => $languageCode,
                    'kind' => $kind,
                ],
                [
                    'subtitle_data' => $subtitles,
                    'segment_count' => count($subtitles),
                ]
            );

            $isNew = $subtitle->wasRecentlyCreated;

            // フィンガープリントを自動生成
            $fingerprintCount = $this->fingerprintService->generateFingerprintsForVideo($videoId);

            Log::info('字幕データ保存成功', [
                'video_id' => $videoId,
                'language_code' => $languageCode,
                'kind' => $kind,
                'segment_count' => count($subtitles),
                'is_new' => $isNew,
                'fingerprints_generated' => $fingerprintCount,
            ]);

            return response()->json([
                'id' => $subtitle->id,
                'segment_count' => $subtitle->segment_count,
                'is_new' => $isNew,
                'fingerprints_generated' => $fingerprintCount,
            ]);
        } catch (Exception $e) {
            Log::error('字幕データ保存エラー', [
                'video_id' => $videoId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => '字幕データの保存に失敗しました'], 500);
        }
    }

    /**
     * 字幕データを取得
     */
    public function show(Request $request)
    {
        $request->validate([
            'video_id' => ['required', 'string', 'size:11', 'regex:/^[A-Za-z0-9_-]{11}$/'],
        ]);

        $videoId = $request->input('video_id');

        $archive = Archive::where('video_id', $videoId)->first();
        if (! $archive) {
            return response()->json(['message' => '指定された動画はアーカイブに登録されていません'], 404);
        }

        $channel = $archive->channel;
        if (! $channel || ! $this->canAccessChannel($channel)) {
            return response()->json(['message' => 'このチャンネルへのアクセス権限がありません'], 403);
        }

        $subtitles = VideoSubtitle::where('video_id', $videoId)->get();

        return response()->json([
            'video_id' => $videoId,
            'subtitles' => $subtitles->map(fn (VideoSubtitle $s) => [
                'id' => $s->id,
                'language_code' => $s->language_code,
                'kind' => $s->kind,
                'segment_count' => $s->segment_count,
                'created_at' => $s->created_at,
                'updated_at' => $s->updated_at,
            ]),
        ]);
    }

    /**
     * 楽曲候補を取得（字幕フィンガープリントベースのマッチング）
     */
    public function matchCandidates(string $tsItemId)
    {
        // ts_itemの存在確認とアクセス権チェック
        $tsItem = \App\Models\TsItem::find($tsItemId);
        if (! $tsItem) {
            return response()->json(['message' => '指定されたタイムスタンプが見つかりません'], 404);
        }

        $archive = Archive::where('video_id', $tsItem->video_id)->first();
        if (! $archive) {
            return response()->json(['message' => '指定された動画はアーカイブに登録されていません'], 404);
        }

        $channel = $archive->channel;
        if (! $channel || ! $this->canAccessChannel($channel)) {
            return response()->json(['message' => 'このチャンネルへのアクセス権限がありません'], 403);
        }

        try {
            $result = $this->matchingService->getCandidateSongs($tsItemId);

            return response()->json($result);
        } catch (Exception $e) {
            Log::error('楽曲マッチングエラー', [
                'ts_item_id' => $tsItemId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => '楽曲マッチングに失敗しました'], 500);
        }
    }
}
