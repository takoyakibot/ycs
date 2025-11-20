<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Google\Client as Google_Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        // APIキーの空文字列をnullに変換（モデルのcastsで自動暗号化される）
        if (isset($validated['api_key'])) {
            $validated['api_key'] = trim($validated['api_key']) ?: null;
        }

        $request->user()->fill($validated);

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's API key.
     */
    public function destroyApiKey(Request $request): RedirectResponse
    {
        $user = $request->user();
        $user->api_key = null;
        $user->save();

        return Redirect::route('profile.edit')->with('status', 'api-key-deleted');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        // Google OAuthトークンをrevokeする
        if ($user->google_token && isset($user->google_token['access_token'])) {
            try {
                $client = new Google_Client;
                $client->revokeToken($user->google_token['access_token']);
                Log::info('Google OAuth token revoked successfully during account deletion', [
                    'user_id' => $user->id,
                ]);
            } catch (\Exception $e) {
                // トークンのrevokeに失敗してもアカウント削除自体は成功させる
                Log::warning('Failed to revoke Google token during account deletion', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Auth::logout();

        // ユーザーに紐づくチャンネルもソフトデリート
        $user->channels()->delete();

        // ユーザーをソフトデリート
        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
