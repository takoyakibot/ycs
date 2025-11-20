<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Providers\RouteServiceProvider;
use Google\Client as Google_Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return redirect()->intended(RouteServiceProvider::HOME);
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        // Google OAuthトークンをrevokeする
        $user = Auth::user();
        if ($user && $user->google_token && isset($user->google_token['access_token'])) {
            try {
                $client = new Google_Client;
                $client->setClientId(config('services.google.client_id'));
                $client->setClientSecret(config('services.google.client_secret'));
                $client->revokeToken($user->google_token['access_token']);

                // revokeに成功したらトークンをクリア
                $user->google_token = null;
                $user->google_refresh_token = null;
                $user->save();

                Log::info('Google OAuth token revoked successfully', [
                    'user_id' => $user->id,
                ]);
            } catch (\Exception $e) {
                // トークンのrevokeに失敗してもログアウト自体は成功させる
                Log::warning('Failed to revoke Google token', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ]);
            }
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
