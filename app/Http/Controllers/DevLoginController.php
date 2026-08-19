<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * ローカル開発用ログイン
 *
 * 本番のログインはGoogle OAuthのみのため、OAuthを通せない環境では
 * 画面の動作確認ができない。その回避用の経路。
 *
 * 有効化の条件と無効化の手順は .env.example の DEV_LOGIN_ENABLED を参照。
 *
 * ガードは3段:
 *   1. 設定/ルートキャッシュが存在しない（キャッシュは .env より優先されるため、
 *      キャッシュがあると 2. と 3. の入力を信用できない）
 *   2. config('dev_login.enabled') === true
 *   3. APP_ENV=local
 *
 * 2. と 3. は同じ bootstrap/cache/config.php に固定されるため独立していない。
 * ローカルで生成したキャッシュが配布されると2つ同時に貫通するので、
 * 1. で無条件に閉じる（fail-closed）。
 */
class DevLoginController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        // キャッシュされた設定・ルートは .env を読まずに使われる。
        // そのため下の2つのガードの入力を信用できない。キャッシュがあれば閉じる。
        if (app()->configurationIsCached() || app()->routesAreCached()) {
            Log::warning('[DevLogin] 設定/ルートキャッシュが有効なため無効化されています。php artisan config:clear route:clear を実行してください');
            abort(404);
        }

        abort_unless(config('dev_login.enabled') === true, 404);
        abort_unless(app()->environment('local'), 404);

        // ログイン先はサーバー側の設定のみで決める。
        // リクエストで指定できるようにすると、この経路が到達可能な環境で
        // 任意ユーザーになりきれてしまう（登録ユーザーは全員が管理機能を使える）
        $email = config('dev_login.email');

        $user = $email
            ? User::where('email', $email)->first()
            : User::orderBy('id')->first();

        abort_if($user === null, 404);

        Auth::login($user);
        $request->session()->regenerate();

        Log::warning('[DevLogin] ローカル開発用ログインが使用されました', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        return redirect()->intended('/');
    }
}
