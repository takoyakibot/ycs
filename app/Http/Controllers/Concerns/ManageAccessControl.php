<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Channel;
use Illuminate\Support\Facades\Auth;

trait ManageAccessControl
{
    /**
     * ユーザーがチャンネルにアクセスできるか判定
     * スーパー管理者は全チャンネルにアクセス可能
     */
    private function canAccessChannel(Channel $channel): bool
    {
        $user = Auth::user();

        return $user->isSuperAdmin() || $channel->user_id === $user->id;
    }
}
