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

            abort(403, 'この機能へのアクセス権限がありません');
        }

        return $next($request);
    }
}
