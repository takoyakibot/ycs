<?php

namespace App\Http\Controllers;

use App\Exceptions\NotFoundException;
use App\Http\Controllers\Concerns\ManageAccessControl;
use App\Models\Channel;
use App\Services\YouTubeService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ManageChannelApiController extends Controller
{
    use ManageAccessControl;

    protected $youtubeService;

    public function __construct(YouTubeService $youtubeService)
    {
        $this->youtubeService = $youtubeService;
    }

    public function fetchChannel(Request $request)
    {
        $user = Auth::user();

        // スーパー管理者は全チャンネルを取得、それ以外は自分のチャンネルのみ
        if ($user->isSuperAdmin()) {
            $channels = Channel::with('user:id,name')->get();
        } else {
            $channels = $user->channels()->get();
        }

        return response()->json($channels);
    }

    public function addChannel(Request $request)
    {
        $request->validate([
            'handle' => 'required|string|regex:/^[a-zA-Z0-9_]+$/|unique:channels,handle',
        ]);

        try {
            $channel = $this->youtubeService->getChannelByHandle($request->handle);
        } catch (Exception $e) {
            Log::error('YouTube API error in addChannel', [
                'handle' => $request->handle,
                'error' => $e->getMessage(),
            ]);
            // サービス層からのユーザーフレンドリーなメッセージをそのまま使用
            throw $e;
        }
        if (! $channel || ! isset($channel['title']) || ! $channel['title']) {
            throw new NotFoundException('チャンネルが存在しません');
        }

        try {
            // DB::transaction + unique constraint で安全に処理
            DB::transaction(function () use ($request, $channel) {
                Channel::create([
                    'handle' => $request->handle,
                    'channel_id' => $channel['channel_id'],
                    'title' => $channel['title'],
                    'thumbnail' => $channel['thumbnail'],
                    'user_id' => Auth::id(),
                ]);
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // unique constraint 違反を検出
            if ($e->getCode() === '23000') {
                throw new Exception('このチャンネルは既に他のユーザーによって登録されています');
            }
            throw $e;
        }

        return response()->json('チャンネルを登録しました');
    }
}
