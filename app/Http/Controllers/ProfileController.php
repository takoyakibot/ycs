<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Crypt;
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
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        if ($request->user()->isDirty('api_key')) {
            $old_user = User::where('id', $request->user()->id)->firstOrFail();
            // 削除の場合はnull
            if ($request->user()->api_key === '削除') {
                $request->user()->api_key = null;
            } elseif ($request->user()->api_key) {
                // 入力されていれば暗号化して更新
                error_log('request: ' . $request->user()->api_key);
                error_log('auth: ' . $old_user->api_key);
                if ($request->user()->api_key !== $old_user->api_key) {
                    $request->user()->api_key = Crypt::encryptString($request->user()->api_key);
                }
            } else {
                // 空の場合は古い値のまま変更しない
                $request->user()->api_key = $old_user->api_key;
            }
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
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

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
