<?php

namespace App\Http\Controllers;

use App\Models\Archive;
use App\Models\Channel;

class ChannelController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // チャンネル情報を取得して表示
        $page = config('utils.page');
        $channels = Channel::paginate($page)->toArray();
        return view('channels.index', compact('channels'));
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        // チャンネル情報を取得して表示
        $channel = Channel::where('handle', $id)->firstOrFail();
        $archives = $this->getArchives($channel->channel_id)->toArray();

        return view('channels.show', compact('channel', 'archives'));
    }

    public function fetchArchives(string $id)
    {
        $channel = Channel::where('handle', $id)->firstOrFail();
        $archives = $this->getArchives($channel->channel_id);
        return response()->json($archives);
    }

    private function getArchives(string $channel_id)
    {
        return Archive::with('tsItemsDisplay')
            ->where('channel_id', $channel_id)
            ->where('is_display', '1')
            ->orderBy('published_at', 'desc')
            ->paginate(config('utils.page'));
    }
}
