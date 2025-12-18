<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    /**
     * 管理者（admin以上）のみアクセス可能
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() || ! $request->user()->canAccessManage()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'この機能へのアクセス権限がありません'], 403);
            }

            return redirect()->route('top')->with('error', 'この機能へのアクセス権限がありません。管理者にお問い合わせください。');
        }

        return $next($request);
    }
}
