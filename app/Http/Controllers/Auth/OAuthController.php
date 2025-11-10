<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\OAuthProvider;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class OAuthController extends Controller
{
    /**
     * Redirect to OAuth provider.
     */
    public function redirectToProvider(string $provider)
    {
        $this->validateProvider($provider);

        return Socialite::driver($provider)->redirect();
    }

    /**
     * Handle OAuth provider callback.
     */
    public function handleProviderCallback(string $provider)
    {
        $this->validateProvider($provider);

        try {
            $socialiteUser = Socialite::driver($provider)->user();
        } catch (\Exception $e) {
            return redirect('/login')->withErrors([
                'oauth' => 'OAuth認証に失敗しました。もう一度お試しください。',
            ]);
        }

        // 既存のOAuthプロバイダーを検索
        $oauthProvider = OAuthProvider::where('provider', $provider)
            ->where('provider_id', $socialiteUser->getId())
            ->first();

        if ($oauthProvider) {
            // 既存のOAuthユーザーの場合、トークンを更新してログイン
            $oauthProvider->update([
                'provider_token' => $socialiteUser->token,
                'provider_refresh_token' => $socialiteUser->refreshToken,
            ]);

            Auth::login($oauthProvider->user);

            return redirect()->intended('/manage');
        }

        // メールアドレスで既存ユーザーを検索
        $user = User::where('email', $socialiteUser->getEmail())->first();

        if ($user) {
            // 既存ユーザーに新しいOAuthプロバイダーを紐付け
            $user->oauthProviders()->create([
                'provider' => $provider,
                'provider_id' => $socialiteUser->getId(),
                'provider_token' => $socialiteUser->token,
                'provider_refresh_token' => $socialiteUser->refreshToken,
            ]);
        } else {
            // 新規ユーザーを作成
            $user = User::create([
                'name' => $socialiteUser->getName() ?? $socialiteUser->getNickname() ?? 'User',
                'email' => $socialiteUser->getEmail(),
                'password' => Hash::make(Str::random(32)), // ランダムなパスワードを生成
                'email_verified_at' => now(), // OAuthユーザーはメール確認済みとする
            ]);

            event(new Registered($user));

            // OAuthプロバイダーを作成
            $user->oauthProviders()->create([
                'provider' => $provider,
                'provider_id' => $socialiteUser->getId(),
                'provider_token' => $socialiteUser->token,
                'provider_refresh_token' => $socialiteUser->refreshToken,
            ]);
        }

        Auth::login($user);

        return redirect()->intended('/manage');
    }

    /**
     * Validate the OAuth provider.
     */
    protected function validateProvider(string $provider): void
    {
        $allowedProviders = ['google', 'github'];

        if (!in_array($provider, $allowedProviders)) {
            abort(404);
        }
    }
}
