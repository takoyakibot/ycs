<?php

namespace Tests\Unit\Jobs;

use App\Jobs\RefreshChannelArchivesJob;
use App\Models\Channel;
use App\Models\User;
use App\Services\RefreshArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RefreshChannelArchivesJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ジョブがキューにディスパッチされることを確認
     */
    public function test_job_is_dispatched_to_queue(): void
    {
        Queue::fake();

        $user = User::factory()->create(['api_key' => 'test-api-key']);
        $channel = Channel::factory()->create(['user_id' => $user->id]);

        RefreshChannelArchivesJob::dispatch($channel, $user->id);

        Queue::assertPushed(RefreshChannelArchivesJob::class, function ($job) use ($channel, $user) {
            return $job->channel->id === $channel->id
                && $job->userId === $user->id;
        });
    }

    /**
     * ジョブが正しいプロパティを持つことを確認
     */
    public function test_job_has_correct_properties(): void
    {
        $user = User::factory()->create(['api_key' => 'test-api-key']);
        $channel = Channel::factory()->create(['user_id' => $user->id]);

        $job = new RefreshChannelArchivesJob($channel, $user->id);

        $this->assertEquals(300, $job->timeout);
        $this->assertEquals(1, $job->tries);
    }

    /**
     * ジョブが実行時にRefreshArchiveServiceを呼び出すことを確認
     */
    public function test_job_calls_refresh_archive_service(): void
    {
        $user = User::factory()->create(['api_key' => 'test-api-key']);
        $channel = Channel::factory()->create(['user_id' => $user->id]);

        $mockService = $this->mock(RefreshArchiveService::class);
        $mockService->shouldReceive('cliLogin')
            ->once()
            ->with((string) $user->id);
        $mockService->shouldReceive('refreshArchives')
            ->once()
            ->with(\Mockery::on(function ($arg) use ($channel) {
                return $arg->id === $channel->id;
            }))
            ->andReturn(10);

        $job = new RefreshChannelArchivesJob($channel, $user->id);
        $job->handle($mockService);
    }
}
