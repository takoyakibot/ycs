<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Archive;
use App\Services\YouTubeService;
use App\Services\ImageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;

class DashboardController extends Controller
{
    protected $youtubeService;
    protected $imageService;

    public function __construct(YouTubeService $youtubeService, ImageService $imageService)
    {
        $this->youtubeService = $youtubeService;
        $this->imageService = $imageService;
    }

    public function index()
    {
        // ログインユーザーのAPIキーを取得
        $user = Auth::user();
        // 登録済みかどうかだけを送る
        $apiKey = $user->api_key ? "1" : "";
        // $hiddenを有効化するために変換してから渡す
        $channels = Channel::all()->toArray();

        return view('dashboard', compact('apiKey', 'channels'));
    }

    public function registerApiKey(Request $request)
    {
        $apiKey = "";
        $channels = Channel::all();

        return view('dashboard', compact('apiKey', 'channels'));
    }

    public function updateApiKey(Request $request)
    {
        $request->validate([
            'api_key' => 'required|string',
        ]);

        // ログインユーザーのAPIキーを暗号化して更新
        $user = Auth::user();
        $user->update(['api_key' => Crypt::encryptString($request->api_key)]);

        return redirect()->route('dashboard')->with('status', 'APIキーを更新しました。');
    }

    public function addChannel(Request $request)
    {
        $request->validate([
            'handle' => 'required|string|unique:channels,handle',
        ]);

        $channel = $this->youtubeService->getChannelByHandle($request->handle);
        if (!$channel || !isset($channel['title']) || !$channel['title']) {
            return redirect()->back()->with('status', 'チャンネルが存在しません。');
        }

        Channel::create([
            'handle' => $request->handle,
            'channel_id' => $channel['channel_id'],
            'title' => $channel['title'],
            'thumbnail' => $channel['thumbnail'],
        ]);

        return redirect()->route('dashboard')->with('status', 'チャンネルを登録しました。');
    }

    public function manageChannel($id)
    {
        $channel = Channel::where('handle', $id)->firstOrFail();
        $archives = Archive::where('channel_id', $channel->channel_id)->get();
        return view('channels.manage', compact('channel', 'archives'));
    }

    public function updateAchives($id)
    {
        $channel = Channel::where('handle', $id)->firstOrFail();
        $archives = $this->youtubeService->getArchives($channel->channel_id);
        // $archives = Archive::where('channel_id', $channel->channel_id)->get();
        return view('channels.manage', compact('channel', 'archives'));
    }
}
