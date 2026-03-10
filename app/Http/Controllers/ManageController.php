<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManageAccessControl;
use App\Models\Archive;
use App\Models\Channel;
use App\Services\YouTubeSubtitleService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class ManageController extends Controller
{
    use ManageAccessControl;

    protected $youtubeSubtitleService;

    public function __construct(
        YouTubeSubtitleService $youtubeSubtitleService
    ) {
        $this->youtubeSubtitleService = $youtubeSubtitleService;
    }

    public function index()
    {
        // APIキーが登録済みかチェック
        $user = Auth::user();
        $api_key_flg = $user->api_key ? '1' : '';

        return view('manage.index', compact('api_key_flg'));
    }

    public function show($id)
    {
        // APIキー未登録の場合はチャンネル管理に戻す（スーパー管理者は除く）
        $user = Auth::user();
        $api_key_flg = $user->api_key ? '1' : '';
        // ハンドルが存在しない場合はチャンネル管理に戻す
        $channel = Channel::where('handle', $id)->first();
        if ((! $api_key_flg && ! $user->isSuperAdmin()) || ! $channel) {
            return redirect()->route('manage.index');
        }

        // アクセス権チェック（所有者またはスーパー管理者）
        if (! $this->canAccessChannel($channel)) {
            abort(403, 'このチャンネルへのアクセス権限がありません');
        }

        $crypt_handle = Crypt::encryptString($channel->handle);

        return view('manage.show', compact('channel', 'crypt_handle'));
    }

    /**
     * チャンネル設定画面を表示
     */
    public function settings(string $id)
    {
        $user = Auth::user();
        $api_key_flg = $user->api_key ? '1' : '';

        $channel = Channel::where('handle', $id)->first();
        if ((! $api_key_flg && ! $user->isSuperAdmin()) || ! $channel) {
            return redirect()->route('manage.index');
        }

        if (! $this->canAccessChannel($channel)) {
            abort(403, 'このチャンネルへのアクセス権限がありません');
        }

        $crypt_handle = Crypt::encryptString($channel->handle);

        return view('manage.settings', compact('channel', 'crypt_handle'));
    }

    /**
     * video_idからアーカイブとチャンネルを取得し、アクセス権を検証する
     *
     * @param  string  $videoId  動画ID
     * @return array{archive: Archive, channel: Channel}
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException アクセス権がない場合
     */
    private function findArchiveWithAccessCheck(string $videoId): array
    {
        $archive = Archive::where('video_id', $videoId)->first();
        if (! $archive) {
            abort(404, '指定された動画はアーカイブに登録されていません');
        }

        $channel = Channel::where('channel_id', $archive->channel_id)->first();
        if (! $channel || ! $this->canAccessChannel($channel)) {
            abort(403, 'このチャンネルへのアクセス権限がありません');
        }

        return ['archive' => $archive, 'channel' => $channel];
    }

    /**
     * アーカイブ動画の字幕を取得
     *
     * InnerTube APIを使用してYouTube動画の字幕（自動生成含む）を取得する。
     * YouTube Data API v3のquotaを消費しない。
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function fetchSubtitles(Request $request)
    {
        $request->validate([
            'video_id' => 'required|string|size:11',
            'lang' => ['nullable', 'string', 'regex:/^[a-zA-Z]{2,3}(-[a-zA-Z0-9]+)*$/', 'max:20'],
        ]);

        $videoId = $request->input('video_id');
        $lang = $request->input('lang', 'ja');

        $this->findArchiveWithAccessCheck($videoId);

        try {
            $subtitles = $this->youtubeSubtitleService->getSubtitles($videoId, $lang);

            return response()->json([
                'video_id' => $videoId,
                'lang' => $lang,
                'count' => count($subtitles),
                'subtitles' => $subtitles,
            ]);
        } catch (Exception $e) {
            Log::warning('fetchSubtitles: 字幕取得エラー', [
                'video_id' => $videoId,
                'lang' => $lang,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * アーカイブ動画の利用可能な字幕トラック一覧を取得
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function fetchSubtitleTracks(Request $request)
    {
        $request->validate([
            'video_id' => 'required|string|size:11',
        ]);

        $videoId = $request->input('video_id');

        $this->findArchiveWithAccessCheck($videoId);

        try {
            $tracks = $this->youtubeSubtitleService->getCaptionTracks($videoId);

            return response()->json([
                'video_id' => $videoId,
                'tracks' => $tracks,
            ]);
        } catch (Exception $e) {
            Log::warning('fetchSubtitleTracks: トラック一覧取得エラー', [
                'video_id' => $videoId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
