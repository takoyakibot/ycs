<?php

namespace App\Services;

use App\Models\Archive;
use App\Models\Channel;

class ChannelQueryService
{
    /**
     * 最も古く更新されたチャンネルを取得
     */
    public function getOldestUpdatedChannel(): Channel
    {
        $archive = Archive::orderBy('created_at', 'asc')->firstOrFail();
        $channel = Channel::where('channel_id', '=', $archive->channel_id)->firstOrFail();

        return $channel;
    }

    /**
     * チャンネル数を取得
     */
    public function getChannelCount(): int
    {
        return Channel::count();
    }

    /**
     * 特定ユーザーの最も古く更新されたチャンネルを取得
     */
    public function getOldestUpdatedChannelForUser(int $userId): ?Channel
    {
        $archive = Archive::whereHas('channel', function ($query) use ($userId) {
            $query->where('user_id', $userId);
        })
            ->orderBy('created_at', 'asc')
            ->first();

        if (! $archive) {
            return null;
        }

        return Channel::where('channel_id', '=', $archive->channel_id)->first();
    }

    /**
     * 特定ユーザーのチャンネル数を取得
     */
    public function getChannelCountForUser(int $userId): int
    {
        return Channel::where('user_id', $userId)->count();
    }
}
