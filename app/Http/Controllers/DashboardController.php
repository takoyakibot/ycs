<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;

class DashboardController extends Controller
{
    public function index()
    {
        // ログインユーザーのAPIキーを取得
        $user = Auth::user();
        $apiKey = $user->api_key ? Crypt::decryptString($user->api_key) : null;
        $channels = Channel::all(); // 登録済みのチャンネル

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

        return redirect()->back()->with('status', 'APIキーを更新しました。');
    }

    public function addChannel(Request $request)
    {
        $request->validate([
            'channel_id' => 'required|string|unique:channels,channel_id',
        ]);

        try {
            Channel::create([
                'channel_id' => $request->channel_id,
                'name' => "サンプル " . $request->channel_id,
            ]);
        } catch (QueryException $e) {
            return redirect()->back()->with('status', 'チャンネルの追加中にエラーが発生しました。');
        }

        return redirect()->back()->with('status', 'チャンネルを登録しました。');
    }

    public function manageChannel($id)
    {
        $channel = Channel::findOrFail($id);
        $archives = Archive::where('channel_id', $channel->channel_id)->get();
        return view('channels.manage', compact('channel', 'archives'));
    }
}
