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
        $channels = Channel::paginate(50)->toArray();
        return view('channels.index', compact('channels'));
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        // $keyword = request('keyword');
        // $cmntChatType = request('type', '0');

        // チャンネル情報を取得して表示
        $channel = Channel::where('handle', $id)->firstOrFail();
        $archives = Archive::where('channel_id', $channel->id)->paginate(50)->toArray();

        // $channelData['comments'] = !$keyword
        // ? []
        // : array_filter(
        //     $channelData['comments'],
        //     function ($comment) use ($keyword, $cmntChatType) {
        //         // タイプ一致かつメッセージが含まれるもののみtrue
        //         return
        //         ($comment['cmntChatType'] === $cmntChatType || $cmntChatType === '0')
        //         ? strpos($comment['message'], $keyword) !== false
        //         : false;
        //     },
        // );

        // if (request()->ajax()) {
        //     return response()->json($channelData['comments']);
        // }
        return view('channels.show', compact('archives'));
    }
}
