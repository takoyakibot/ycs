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
 * config/dev_login.php の enabled が true、かつ APP_ENV=local の場合のみ動作する。
 * どちらか一方でも欠ければ 404 を返すので、本番環境では有効化できない。
 */
class DevLoginController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless(config('dev_login.enabled') === true, 404);
        abort_unless(app()->environment('local'), 404);

        $email = $request->query('email') ?: config('dev_login.email');

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
