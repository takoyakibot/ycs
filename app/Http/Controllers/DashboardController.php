<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function index()
    {
        // ログインユーザーのAPIキーを取得
        $user = Auth::user();
        $apiKey = $user->api_key;
        $channels = [];//Channel::all(); // 登録済みのチャンネル

        return view('dashboard', compact('apiKey', 'channels'));
    }

    public function updateApiKey(Request $request)
    {
        $request->validate([
            'api_key' => 'required|string',
        ]);

        // ログインユーザーのAPIキーを更新
        $user = Auth::user();
        $user->update(['api_key' => $request->api_key]);

        return redirect()->back()->with('status', 'APIキーを更新しました。');
    }

    public function addChannel(Request $request)
    {
        $request->validate([
            'channel_id' => 'required|string|unique:channels,channel_id',
        ]);

        Channel::create([
            'channel_id' => $request->channel_id,
        ]);

        return redirect()->route('dashboard')->with('status', 'チャンネルを登録しました。');
    }

    public function manageChannel($id)
    {
        $channel = Channel::findOrFail($id);
        $archives = Archive::where('channel_id', $channel->channel_id)->get();
        return view('channels.manage', compact('channel', 'archives'));
    }
}
