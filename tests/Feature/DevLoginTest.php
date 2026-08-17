<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DevLoginTest extends TestCase
{
    use RefreshDatabase;

    /**
     * local 環境で有効化した状態にする
     */
    private function enableInLocal(): void
    {
        $this->app['env'] = 'local';
        config()->set('dev_login.enabled', true);
    }

    /**
     * 既定では無効（404）であること
     */
    public function test_disabled_by_default(): void
    {
        User::factory()->create();

        $this->get('/dev-login')->assertNotFound();
        $this->assertGuest();
    }

    /**
     * 有効化しても local 以外では 404 であること
     */
    public function test_not_available_outside_local(): void
    {
        User::factory()->create();

        // APP_ENV は testing のまま
        config()->set('dev_login.enabled', true);

        $this->get('/dev-login')->assertNotFound();
        $this->assertGuest();
    }

    /**
     * local 環境で有効化されていればログインできること
     */
    public function test_logs_in_when_enabled_in_local(): void
    {
        $user = User::factory()->create();

        $this->enableInLocal();

        $this->get('/dev-login')->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    /**
     * 設定でユーザーを指定できること
     */
    public function test_logs_in_as_configured_email(): void
    {
        User::factory()->create(['email' => 'first@example.com']);
        $target = User::factory()->create(['email' => 'target@example.com']);

        $this->enableInLocal();
        config()->set('dev_login.email', 'target@example.com');

        $this->get('/dev-login')->assertRedirect();
        $this->assertAuthenticatedAs($target);
    }

    /**
     * クエリパラメータでユーザーを指定できること
     */
    public function test_logs_in_as_query_email(): void
    {
        User::factory()->create(['email' => 'first@example.com']);
        $target = User::factory()->create(['email' => 'target@example.com']);

        $this->enableInLocal();

        $this->get('/dev-login?email=target@example.com')->assertRedirect();
        $this->assertAuthenticatedAs($target);
    }

    /**
     * 該当ユーザーがいなければ 404 であること
     */
    public function test_not_found_when_user_missing(): void
    {
        $this->enableInLocal();

        $this->get('/dev-login?email=nobody@example.com')->assertNotFound();
        $this->assertGuest();
    }

    /**
     * ユーザーが1人もいない場合も 404 であること
     */
    public function test_not_found_when_no_users_exist(): void
    {
        $this->enableInLocal();

        $this->get('/dev-login')->assertNotFound();
        $this->assertGuest();
    }
}
