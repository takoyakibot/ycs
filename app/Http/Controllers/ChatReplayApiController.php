<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManageAccessControl;
use App\Models\Archive;
use App\Models\ChatReplayData;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ChatReplayApiController extends Controller
{
    use ManageAccessControl;

    public function store(Request $request)
    {
        $request->validate([
            'video_id' => ['required', 'string', 'size:11', 'regex:/^[A-Za-z0-9_-]{11}$/'],
            'duration' => ['required', 'numeric', 'min:0'],
            'chat_data' => ['required', 'array', 'min:1', 'max:50000'],
            'chat_data.*.message' => ['required', 'string'],
            'chat_data.*.timestamp' => ['required', 'integer', 'min:0'],
            'chat_data.*.type' => ['required', 'string', 'in:normal,superchat'],
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
            $chatData = $request->input('chat_data');
            $messageCount = count($chatData);

            $chatReplayData = ChatReplayData::updateOrCreate(
                ['video_id' => $videoId],
                [
                    'duration' => $request->input('duration'),
                    'message_count' => $messageCount,
                    'chat_data' => $chatData,
                ]
            );

            $isNew = $chatReplayData->wasRecentlyCreated;

            Log::info('チャットリプレイデータ保存成功', [
                'video_id' => $videoId,
                'duration' => $request->input('duration'),
                'message_count' => $messageCount,
                'is_new' => $isNew,
            ]);

            return response()->json([
                'id' => $chatReplayData->id,
                'video_id' => $videoId,
                'message_count' => $messageCount,
                'is_new' => $isNew,
            ]);
        } catch (Exception $e) {
            Log::error('チャットリプレイデータ保存エラー', [
                'video_id' => $videoId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'チャットリプレイデータの保存に失敗しました'], 500);
        }
    }
}
