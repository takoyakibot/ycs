<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        $user = $request->user();
        $user->api_key = $user->api_key ? '1' : '';

        return view('profile.edit', [
            'user' => $user,
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
            // 削除の場合はnull
            if ($request->user()->api_key === '削除') {
                $request->user()->api_key = null;
            } elseif ($request->user()->api_key) {
                // 別の値が入力されていれば暗号化して更新
                if ($request->user()->api_key !== '1') {
                    $request->user()->api_key = Crypt::encryptString($request->user()->api_key);
                }
            } else {
                // 空の場合は古い値のまま変更しない
                $old_user = User::where('id', $request->user()->id)->firstOrFail();
                $request->user()->api_key = $old_user->api_key;
            }
        }
        // productionの場合はapi_keyの新規登録は受け付けない
        if (config('app.env') == 'production') {
            $request->user()->api_key = '';
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
