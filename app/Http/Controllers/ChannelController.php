<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ChannelController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // チャンネル情報を取得して表示
        $channels = [
            ['id' => 1, 'name' => 'チャンネルA'],
            ['id' => 2, 'name' => 'チャンネルB'],
        ];

        return view('channels.index', compact('channels'));
    }

    // /**
    //  * Show the form for creating a new resource.
    //  */
    // public function create()
    // {
    //     //
    // }

    // /**
    //  * Store a newly created resource in storage.
    //  */
    // public function store(Request $request)
    // {
    //     //
    // }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $keyword = request('keyword');
        $cmntChatType = request('type');

        // チャンネル情報を取得して表示
        $channelData = [
            'name' => 'チャンネル'.$id,
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

        return view('channels.show', compact('channelData'));
    }

    // /**
    //  * Show the form for editing the specified resource.
    //  */
    // public function edit(string $id)
    // {
    //     //
    // }

    // /**
    //  * Update the specified resource in storage.
    //  */
    // public function update(Request $request, string $id)
    // {
    //     //
    // }

    // /**
    //  * Remove the specified resource from storage.
    //  */
    // public function destroy(string $id)
    // {
    //     //
    // }
}
