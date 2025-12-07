<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class UserActionLogControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_log_user_action(): void
    {
        Log::shouldReceive('channel')
            ->once()
            ->with('user_actions')
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($message) {
                $data = json_decode($message, true);

                return $data['action'] === 'testAction'
                    && $data['data']['key'] === 'value';
            });

        $response = $this->postJson('/api/user-actions/log', [
            'action' => 'testAction',
            'data' => ['key' => 'value'],
        ]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'ok']);
    }

    public function test_can_log_without_data(): void
    {
        Log::shouldReceive('channel')
            ->once()
            ->with('user_actions')
            ->andReturnSelf();

        Log::shouldReceive('info')
            ->once();

        $response = $this->postJson('/api/user-actions/log', [
            'action' => 'simpleAction',
        ]);

        $response->assertStatus(200);
    }

    public function test_validation_fails_without_action(): void
    {
        $response = $this->postJson('/api/user-actions/log', [
            'data' => ['key' => 'value'],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['action']);
    }

    public function test_validation_fails_with_too_long_action(): void
    {
        $response = $this->postJson('/api/user-actions/log', [
            'action' => str_repeat('a', 101),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['action']);
    }

    public function test_rate_limiting_is_applied(): void
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info')->andReturn(null);

        // 60回のリクエストは成功する
        for ($i = 0; $i < 60; $i++) {
            $response = $this->postJson('/api/user-actions/log', [
                'action' => 'testAction',
            ]);
            $response->assertStatus(200);
        }

        // 61回目はレートリミットに引っかかる
        $response = $this->postJson('/api/user-actions/log', [
            'action' => 'testAction',
        ]);
        $response->assertStatus(429);
    }
}
