<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Archive;
use App\Services\YouTubeService;
use App\Services\ImageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        // $hiddenを有効化するために変換してから渡す
        $channels = Channel::all()->toArray();
        return view('dashboard', compact('channels'));
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
        [$archives, $ts_items] = $this->youtubeService->getArchives($channel->channel_id);

        DB::transaction(function () use ($channel, $archives, $ts_items) {
            Archive::where('channel_id', $channel->channel_id)->delete();
            DB::table('archives')->insert($archives);
            DB::table('ts_items')->insert($ts_items);
        });
        // $archives = Archive::where('channel_id', $channel->channel_id)->get();
        return view('channels.manage', compact('channel', 'archives'));
    }
}
