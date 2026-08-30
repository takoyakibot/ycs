<?php

namespace Tests\Feature\Components;

use Illuminate\Support\Carbon;
use Tests\TestCase;

class DatetimeComponentTest extends TestCase
{
    public function test_renders_utc_datetime_in_jst(): void
    {
        $utcTime = Carbon::parse('2026-01-15 03:30:00', 'UTC');

        $view = $this->blade(
            '<x-datetime :value="$value" />',
            ['value' => $utcTime]
        );

        $view->assertSee('2026-01-15 12:30');
    }

    public function test_renders_nothing_for_null(): void
    {
        $view = $this->blade(
            '<x-datetime :value="$value" />',
            ['value' => null]
        );

        $view->assertDontSee('1970');
        $view->assertDontSee('2026');
    }

    public function test_custom_format(): void
    {
        $utcTime = Carbon::parse('2026-01-15 03:30:00', 'UTC');

        $view = $this->blade(
            '<x-datetime :value="$value" format="Y/m/d" />',
            ['value' => $utcTime]
        );

        $view->assertSee('2026/01/15');
    }

    public function test_accepts_string_input(): void
    {
        $view = $this->blade(
            '<x-datetime :value="$value" />',
            ['value' => '2026-01-15 03:30:00']
        );

        $view->assertSee('2026-01-15 12:30');
    }
}
