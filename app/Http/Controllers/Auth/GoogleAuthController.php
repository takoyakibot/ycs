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
            ->scopes([
                'https://www.googleapis.com/auth/youtube.readonly',
            ])
            ->with([
                'access_type' => 'offline',
                'prompt' => 'consent select_account',
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

            $user = User::updateOrCreate(
                ['google_id' => $googleUser->getId()],
                [
                    'name' => $googleUser->getName(),
                    'email' => $googleUser->getEmail(),
                    'avatar' => $googleUser->getAvatar(),
                    'google_token' => $tokenArray,
                    'google_refresh_token' => $googleUser->refreshToken,
                    'email_verified_at' => now(), // Google認証済みならメール認証も済みとみなす
                ]
            );

            Auth::login($user);

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
