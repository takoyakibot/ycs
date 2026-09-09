<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManageAccessControl;
use App\Models\Archive;
use App\Models\SpectralScanData;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SpectralApiController extends Controller
{
    use ManageAccessControl;

    public function store(Request $request)
    {
        $request->validate([
            'video_id' => ['required', 'string', 'size:11', 'regex:/^[A-Za-z0-9_-]{11}$/'],
            'sampling_interval' => ['required', 'integer', 'min:1'],
            'duration' => ['required', 'numeric', 'min:0'],
            'spectral_data' => ['required', 'array', 'min:1'],
            'spectral_data.*' => ['nullable'],
            'spectral_data.*.flatness' => ['numeric', 'min:0', 'max:1'],
            'spectral_data.*.voiceBandRatio' => ['numeric', 'min:0', 'max:1'],
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

        try {
            $spectralScanData = SpectralScanData::updateOrCreate(
                ['video_id' => $videoId],
                [
                    'sampling_interval' => $request->input('sampling_interval'),
                    'duration' => $request->input('duration'),
                    'spectral_data' => $request->input('spectral_data'),
                ]
            );

            $isNew = $spectralScanData->wasRecentlyCreated;
            $dataCount = count(array_filter($request->input('spectral_data'), fn ($s) => $s !== null));

            Log::info('スペクトルデータ保存成功', [
                'video_id' => $videoId,
                'sampling_interval' => $request->input('sampling_interval'),
                'duration' => $request->input('duration'),
                'data_points' => $dataCount,
                'is_new' => $isNew,
            ]);

            return response()->json([
                'id' => $spectralScanData->id,
                'video_id' => $videoId,
                'data_points' => $dataCount,
                'is_new' => $isNew,
            ]);
        } catch (Exception $e) {
            Log::error('スペクトルデータ保存エラー', [
                'video_id' => $videoId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'スペクトルデータの保存に失敗しました'], 500);
        }
    }
}
