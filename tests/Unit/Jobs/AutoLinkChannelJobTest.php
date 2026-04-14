<?php

namespace Tests\Unit\Jobs;

use App\Jobs\AutoLinkChannelJob;
use App\Models\Channel;
use App\Services\AutoLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class AutoLinkChannelJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ジョブがAutoLinkServiceをチャンネルIDで呼び出すことを確認
     */
    public function test_job_calls_auto_link_service_with_channel_id(): void
    {
        $channel = Channel::factory()->create();

        $mockService = Mockery::mock(AutoLinkService::class);
        $mockService->shouldReceive('autoLinkUnlinkedTimestamps')
            ->once()
            ->with(
                Mockery::on(fn ($limit) => $limit === 10000),
                Mockery::on(fn ($onProgress) => is_callable($onProgress)),
                Mockery::on(fn ($channelId) => $channelId === $channel->channel_id),
            )
            ->andReturn([
                'processed' => 5,
                'linked' => 3,
                'pending' => 1,
                'failed' => 0,
                'skipped' => 1,
            ]);

        $job = new AutoLinkChannelJob($channel);
        $job->handle($mockService);
    }

    /**
     * ジョブが開始・完了ログを出力することを確認
     */
    public function test_job_logs_start_and_completion(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->with('AutoLinkChannelJob started', Mockery::on(fn ($ctx) => isset($ctx['channel_id']) && isset($ctx['handle'])));

        Log::shouldReceive('info')
            ->with(Mockery::pattern('/^\[AutoLink:/'), Mockery::any())
            ->zeroOrMoreTimes();

        Log::shouldReceive('info')
            ->once()
            ->with('AutoLinkChannelJob completed', Mockery::on(fn ($ctx) => isset($ctx['channel_id']) && isset($ctx['result'])));

        $channel = Channel::factory()->create();

        $mockService = Mockery::mock(AutoLinkService::class);
        $mockService->shouldReceive('autoLinkUnlinkedTimestamps')
            ->once()
            ->andReturn([
                'processed' => 0,
                'linked' => 0,
                'pending' => 0,
                'failed' => 0,
                'skipped' => 0,
            ]);

        $job = new AutoLinkChannelJob($channel);
        $job->handle($mockService);
    }

    /**
     * failed()メソッドがエラーログを出力することを確認
     */
    public function test_failed_method_logs_error(): void
    {
        $channel = Channel::factory()->create();

        Log::shouldReceive('error')
            ->once()
            ->with('AutoLinkChannelJob permanently failed', Mockery::on(function ($context) use ($channel) {
                return $context['channel_id'] === $channel->channel_id
                    && $context['error'] === 'Test error message';
            }));

        $job = new AutoLinkChannelJob($channel);
        $job->failed(new \RuntimeException('Test error message'));
    }
}
