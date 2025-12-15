<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    /**
     * 管理者管理画面を表示
     */
    public function index()
    {
        return view('manage.admins');
    }

    /**
     * 管理者一覧を取得
     */
    public function fetchAdmins()
    {
        $admins = User::where('role', User::ROLE_ADMIN)
            ->select('id', 'name', 'email', 'role', 'created_at')
            ->withCount('channels')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $admins,
        ]);
    }

    /**
     * 管理者を登録（メールアドレスで指定）
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email|max:255',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user) {
            return response()->json([
                'message' => '指定されたメールアドレスのユーザーが見つかりません。先にGoogleログインしてもらってください。',
            ], 404);
        }

        if ($user->isSuperAdmin()) {
            return response()->json([
                'message' => 'このユーザーは既にスーパー管理者です。',
            ], 422);
        }

        if ($user->role === User::ROLE_ADMIN) {
            return response()->json([
                'message' => 'このユーザーは既に管理者です。',
            ], 422);
        }

        $user->update(['role' => User::ROLE_ADMIN]);

        return response()->json([
            'message' => '管理者として登録しました。',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ], 201);
    }

    /**
     * 管理者権限を削除
     */
    public function destroy(Request $request, $id)
    {
        $user = User::findOrFail($id);

        if ($user->isSuperAdmin()) {
            return response()->json([
                'message' => 'スーパー管理者の権限は削除できません。',
            ], 422);
        }

        if ($user->role !== User::ROLE_ADMIN) {
            return response()->json([
                'message' => 'このユーザーは管理者ではありません。',
            ], 422);
        }

        $user->update(['role' => User::ROLE_USER]);

        return response()->json([
            'message' => '管理者権限を削除しました。',
        ]);
    }
}
