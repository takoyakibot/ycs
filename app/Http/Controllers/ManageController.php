<?php

namespace App\Http\Controllers;

use App\Models\Archive;
use App\Models\Channel;
use App\Services\ImageService;
use App\Services\YouTubeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ManageController extends Controller
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
        return view('manage.channels', compact('channels'));
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

        return redirect()->route('manage')->with('status', 'チャンネルを登録しました。');
    }

    public function manageChannel($id)
    {
        $channel = Channel::where('handle', $id)->firstOrFail();
        $archives = Archive::where('channel_id', $channel->channel_id)->get();
        return view('manage.archives', compact('channel', 'archives'));
    }

    public function updateAchives($id)
    {
        $channel = Channel::where('handle', $id)->firstOrFail();

        DB::transaction(function () use ($channel) {
            [$rtn_archives, $rtn_ts_items] = $this->youtubeService->getArchivesAndTsItems($channel->channel_id);

            Archive::where('channel_id', $channel->channel_id)->delete();
            if (!empty($rtn_archives)) {
                DB::table('archives')->insert($rtn_archives);
            }
            if (!empty($rtn_ts_items)) {
                DB::table('ts_items')->insert($rtn_ts_items);
            }
        });

        $archives = Archive::with('tsItems')
            ->where('channel_id', $channel->channel_id)
            ->get();
        return view('manage.archives', compact('channel', 'archives'));
    }
}
