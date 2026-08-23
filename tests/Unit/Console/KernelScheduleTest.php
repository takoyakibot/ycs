<?php

namespace Tests\Unit\Console;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class KernelScheduleTest extends TestCase
{
    public function test_review_status_command_is_scheduled(): void
    {
        $schedule = app(Schedule::class);
        $events = collect($schedule->events());

        $hasReviewStatus = $events->contains(function ($event) {
            return str_contains($event->command, 'songs:review-status');
        });

        $this->assertTrue($hasReviewStatus, 'songs:review-status がスケジュールに登録されていません');
    }
}
