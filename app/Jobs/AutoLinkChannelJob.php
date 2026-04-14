<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Services\AutoLinkService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AutoLinkChannelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** ジョブのタイムアウト（10分：Spotify API呼び出しのため長め） */
    public int $timeout = 600;

    /** リトライ回数 */
    public int $tries = 1;

    public function __construct(
        public Channel $channel,
    ) {}

    public function handle(AutoLinkService $autoLinkService): void
    {
        Log::info('AutoLinkChannelJob started', [
            'channel_id' => $this->channel->channel_id,
            'handle' => $this->channel->handle,
        ]);

        $result = $autoLinkService->autoLinkUnlinkedTimestamps(
            limit: 10000,
            onProgress: fn ($msg) => Log::info("[AutoLink:{$this->channel->handle}] {$msg}"),
            channelId: $this->channel->channel_id,
        );

        Log::info('AutoLinkChannelJob completed', [
            'channel_id' => $this->channel->channel_id,
            'result' => $result,
        ]);
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('AutoLinkChannelJob permanently failed', [
            'channel_id' => $this->channel->channel_id,
            'error' => $exception?->getMessage(),
        ]);
    }
}
