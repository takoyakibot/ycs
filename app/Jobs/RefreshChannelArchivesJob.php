<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Services\RefreshArchiveService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RefreshChannelArchivesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** ジョブのタイムアウト（5分） */
    public int $timeout = 300;

    /** リトライ回数 */
    public int $tries = 1;

    public Channel $channel;

    public int $userId;

    /**
     * Create a new job instance.
     */
    public function __construct(Channel $channel, int $userId)
    {
        $this->channel = $channel;
        $this->userId = $userId;
    }

    /**
     * Execute the job.
     */
    public function handle(RefreshArchiveService $refreshArchiveService): void
    {
        Log::info('RefreshChannelArchivesJob started', [
            'channel_id' => $this->channel->channel_id,
            'handle' => $this->channel->handle,
            'user_id' => $this->userId,
        ]);

        try {
            // キューワーカーはセッションがないため、CLIログインでユーザーを設定
            $refreshArchiveService->cliLogin((string) $this->userId);

            $count = $refreshArchiveService->refreshArchives($this->channel);

            Log::info('RefreshChannelArchivesJob completed', [
                'channel_id' => $this->channel->channel_id,
                'handle' => $this->channel->handle,
                'archives_count' => $count,
            ]);
        } catch (\Exception $e) {
            Log::error('RefreshChannelArchivesJob failed', [
                'channel_id' => $this->channel->channel_id,
                'handle' => $this->channel->handle,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error('RefreshChannelArchivesJob permanently failed', [
            'channel_id' => $this->channel->channel_id,
            'handle' => $this->channel->handle,
            'error' => $exception?->getMessage(),
        ]);
    }
}
