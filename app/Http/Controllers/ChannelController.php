<?php

namespace App\Http\Controllers;

use App\Models\Channel;

class ChannelController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // チャンネル情報を取得して表示
        $channels = Channel::paginate(100)->toArray();
        return view('channels.index', compact('channels'));
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $keyword = request('keyword');
        $cmntChatType = request('type', '0');

        // チャンネル情報を取得して表示
        $channelData = [
            'name' => 'チャンネル' . $id,
            'comments' => [
                ['id' => 1, 'cmntChatType' => '1', 'timestamp' => '2024-10-10 10:00', 'message' => 'コメントA'],
                ['id' => 2, 'cmntChatType' => '1', 'timestamp' => '2024-10-20 10:00', 'message' => 'コメントB'],
                ['id' => 3, 'cmntChatType' => '2', 'timestamp' => '10:00', 'message' => 'チャットA'],
                ['id' => 4, 'cmntChatType' => '2', 'timestamp' => '20:00', 'message' => 'チャットB'],
            ],
        ];

        $channelData['comments'] = !$keyword
        ? []
        : array_filter(
            $channelData['comments'],
            function ($comment) use ($keyword, $cmntChatType) {
                // タイプ一致かつメッセージが含まれるもののみtrue
                return
                ($comment['cmntChatType'] === $cmntChatType || $cmntChatType === '0')
                ? strpos($comment['message'], $keyword) !== false
                : false;
            },
        );

        if (request()->ajax()) {
            return response()->json($channelData['comments']);
        }
        return view('channels.show', compact('channelData'));
    }
}
