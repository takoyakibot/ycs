<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManageAccessControl;
use App\Models\Channel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;

class ManageController extends Controller
{
    use ManageAccessControl;

    public function index()
    {
        // APIキーが登録済みかチェック
        $user = Auth::user();
        $api_key_flg = $user->api_key ? '1' : '';

        return view('manage.index', compact('api_key_flg'));
    }

    public function show($id)
    {
        // APIキー未登録の場合はチャンネル管理に戻す（スーパー管理者は除く）
        $user = Auth::user();
        $api_key_flg = $user->api_key ? '1' : '';
        // ハンドルが存在しない場合はチャンネル管理に戻す
        $channel = Channel::where('handle', $id)->first();
        if ((! $api_key_flg && ! $user->isSuperAdmin()) || ! $channel) {
            return redirect()->route('manage.index');
        }

        // アクセス権チェック（所有者またはスーパー管理者）
        if (! $this->canAccessChannel($channel)) {
            abort(403, 'このチャンネルへのアクセス権限がありません');
        }

        $crypt_handle = Crypt::encryptString($channel->handle);

        return view('manage.show', compact('channel', 'crypt_handle'));
    }

    /**
     * チャンネル設定画面を表示
     */
    public function settings(string $id)
    {
        $user = Auth::user();
        $api_key_flg = $user->api_key ? '1' : '';

        $channel = Channel::where('handle', $id)->first();
        if ((! $api_key_flg && ! $user->isSuperAdmin()) || ! $channel) {
            return redirect()->route('manage.index');
        }

        if (! $this->canAccessChannel($channel)) {
            abort(403, 'このチャンネルへのアクセス権限がありません');
        }

        $crypt_handle = Crypt::encryptString($channel->handle);

        return view('manage.settings', compact('channel', 'crypt_handle'));
    }
}
