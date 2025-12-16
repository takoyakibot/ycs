<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsSuperAdmin
{
    /**
     * スーパー管理者のみアクセス可能
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() || ! $request->user()->canAccessSuperAdminFeatures()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'この機能へのアクセス権限がありません'], 403);
            }

            abort(403, 'この機能へのアクセス権限がありません');
        }

        return $next($request);
    }
}
