<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UserActionLogController extends Controller
{
    /**
     * ユーザー操作ログを記録
     */
    public function log(Request $request)
    {
        $validated = $request->validate([
            'action' => 'required|string|max:100',
            'data' => 'nullable|array',
        ]);

        $logData = [
            'action' => $validated['action'],
            'data' => $validated['data'] ?? [],
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'timestamp' => now()->toIso8601String(),
        ];

        Log::channel('user_actions')->info(json_encode($logData, JSON_UNESCAPED_UNICODE));

        return response()->json(['status' => 'ok']);
    }
}
