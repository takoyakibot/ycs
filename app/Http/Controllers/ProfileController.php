<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Google\Client as Google_Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\ValidationException;
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
            'maxApiTokens' => self::MAX_API_TOKENS,
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
        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * 1ユーザーあたりのAPIトークン発行上限
     */
    public const MAX_API_TOKENS = 5;

    /**
     * APIトークンを発行
     */
    public function createApiToken(Request $request): RedirectResponse
    {
        $request->validate([
            'token_name' => ['required', 'string', 'max:255'],
        ]);

        // 上限に達している場合は既存トークンに影響を与えずに拒否する
        if ($request->user()->tokens()->count() >= self::MAX_API_TOKENS) {
            throw ValidationException::withMessages([
                'token_name' => 'APIトークンは最大'.self::MAX_API_TOKENS.'個までです。不要なトークンを失効してから発行してください。',
            ]);
        }

        $token = $request->user()->createToken($request->input('token_name'));

        return Redirect::route('profile.edit')->with('new_api_token', $token->plainTextToken);
    }

    /**
     * APIトークンを個別に失効
     */
    public function destroyApiToken(Request $request, string $token): RedirectResponse
    {
        // 他ユーザーのトークンを失効できないよう、必ず自分のトークンから検索する
        $target = $request->user()->tokens()->whereKey($token)->first();

        if ($target === null) {
            abort(404);
        }

        $target->delete();

        return Redirect::route('profile.edit')->with('status', 'api-token-deleted');
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
                $client->setClientId(config('services.google.client_id'));
                $client->setClientSecret(config('services.google.client_secret'));
                $client->revokeToken($user->google_token['access_token']);

                // revokeに成功したらトークンをクリア
                $user->google_token = null;
                $user->google_refresh_token = null;
                $user->save();

                Log::info('Google OAuth token revoked successfully during account deletion', [
                    'user_id' => $user->id,
                ]);
            } catch (\Exception $e) {
                // トークンのrevokeに失敗してもアカウント削除自体は成功させる
                Log::warning('Failed to revoke Google token during account deletion', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
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
