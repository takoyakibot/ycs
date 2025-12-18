<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    /**
     * Google OAuth認証画面へリダイレクト
     */
    public function redirect()
    {
        return Socialite::driver('google')
            ->with([
                'access_type' => 'offline',
                'prompt' => 'select_account', // アカウント選択のみ（初回のみ同意画面）
            ])
            ->redirect();
    }

    /**
     * Google OAuth認証後のコールバック処理
     */
    public function callback()
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();

            // Google Clientが期待する形式でトークンを保存
            $tokenArray = [
                'access_token' => $googleUser->token,
                'expires_in' => $googleUser->expiresIn ?? 3600,
                'created' => time(),
            ];

            // メールアドレスの@より前の部分を表示名として使用
            // （プライバシー保護のため、Googleアカウントの氏名は収集しない）
            $displayName = explode('@', $googleUser->getEmail())[0];

            // まずgoogle_idで検索
            $user = User::where('google_id', $googleUser->getId())->first();

            if ($user) {
                // 同じGoogleアカウントでログイン：すべての情報を更新
                $user->update([
                    'name' => $displayName,
                    'email' => $googleUser->getEmail(),
                    'avatar' => $googleUser->getAvatar(),
                    'google_token' => $tokenArray,
                    'google_refresh_token' => $googleUser->refreshToken,
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ]);
            } else {
                // google_idで見つからない場合、emailで検索
                // （通常登録済みユーザーがGoogle認証する場合に対応）
                $user = User::where('email', $googleUser->getEmail())->first();

                if ($user) {
                    // 既存ユーザーにGoogleアカウントを紐付け
                    $user->update([
                        'google_id' => $googleUser->getId(),
                        'name' => $displayName,
                        'avatar' => $googleUser->getAvatar(),
                        'google_token' => $tokenArray,
                        'google_refresh_token' => $googleUser->refreshToken,
                        'email_verified_at' => $user->email_verified_at ?? now(),
                    ]);
                } else {
                    // 新規ユーザーを作成
                    $user = User::create([
                        'google_id' => $googleUser->getId(),
                        'name' => $displayName,
                        'email' => $googleUser->getEmail(),
                        'avatar' => $googleUser->getAvatar(),
                        'google_token' => $tokenArray,
                        'google_refresh_token' => $googleUser->refreshToken,
                        'email_verified_at' => now(),
                    ]);
                }
            }

            Auth::login($user, true);  // Remember Me を有効化

            return redirect()->intended(RouteServiceProvider::HOME);
        } catch (\Exception $e) {
            Log::error('Google OAuth callback error: '.$e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect('/login')->withErrors([
                'email' => 'Google認証に失敗しました。もう一度お試しください。',
            ]);
        }
    }
}
