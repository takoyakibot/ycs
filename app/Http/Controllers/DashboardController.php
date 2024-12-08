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
        $apiKey = $user->api_key ? "1" : ""; // 登録済みかどうかだけを送る
        $channels = Channel::all(); // 登録済みのチャンネル

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
            'channel_id' => 'required|string|unique:channels,channel_id',
        ]);

        $this->youtubeService->setApiKey(
            Crypt::decryptString(Auth::user()->api_key)
        );
        $channel = $this->youtubeService->getChannelByHandle($request->channel_id);
        if (!$channel || !isset($channel['title']) || !$channel['title']) {
            return redirect()->back()->with('status', 'チャンネルが存在しません。');
        }

        $thumbnail = $this->imageService->downloadThumbnail($channel['thumbnail']);
        if (!$thumbnail) {
            return redirect()->back()->with('status', 'サムネイルの取得に失敗しました。');
        }

        Channel::create([
            'channel_id' => $request->channel_id,
            'name' => $channel['title'],
            'thumbnail' => $thumbnail,
        ]);

        return redirect()->route('dashboard')->with('status', 'チャンネルを登録しました。');
    }

    public function manageChannel($id)
    {
        $channel = Channel::where('channel_id', $id)->firstOrFail();
        $archives = Archive::where('channel_id', $channel->channel_id)->get();
        return view('channels.manage', compact('channel', 'archives'));
    }

    public function updateAchives($id)
    {

        $channel = Channel::where('channel_id', $id)->firstOrFail();
        $archives = Archive::where('channel_id', $channel->channel_id)->get();
        return view('channels.update-achives', compact('channel', 'archives'));
    }
}
