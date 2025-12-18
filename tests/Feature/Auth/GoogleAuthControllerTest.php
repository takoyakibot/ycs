<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Tests\TestCase;

class GoogleAuthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_redirect_to_google_oauth(): void
    {
        $response = $this->get('/auth/google');

        // Socialiteがリダイレクトを返すことを確認
        $response->assertRedirect();
    }

    public function test_callback_creates_new_user(): void
    {
        // Socialiteをモック
        $mockUser = \Mockery::mock(SocialiteUser::class);
        $mockUser->shouldReceive('getId')->andReturn('google_123');
        $mockUser->shouldReceive('getName')->andReturn('Test User');
        $mockUser->shouldReceive('getEmail')->andReturn('test@example.com');
        $mockUser->shouldReceive('getAvatar')->andReturn('https://example.com/avatar.jpg');
        $mockUser->shouldReceive('getAccessToken')->andReturn('test_access_token');
        $mockUser->token = 'test_access_token';
        $mockUser->expiresIn = 3600;
        $mockUser->refreshToken = 'test_refresh_token';

        Socialite::shouldReceive('driver->stateless->user')->andReturn($mockUser);

        $response = $this->get('/auth/google/callback');

        // ユーザーが作成されたことを確認
        $user = User::where('google_id', 'google_123')->first();
        $this->assertNotNull($user);
        // プライバシー保護のため、Googleの氏名ではなくメールアドレスの@前を使用
        $this->assertEquals('test', $user->name);
        $this->assertEquals('test@example.com', $user->email);

        // email_verified_atが設定されていることを確認（Google認証済みならメール認証も済みとみなす）
        $this->assertNotNull($user->email_verified_at);

        // ログインしてHOMEにリダイレクトされることを確認
        $response->assertRedirect('/manage');
        $this->assertAuthenticated();
    }

    public function test_callback_updates_existing_user(): void
    {
        // 既存ユーザーを作成
        $existingUser = User::factory()->create([
            'google_id' => 'google_123',
            'name' => 'Old Name',
            'email' => 'old@example.com',
        ]);

        // Socialiteをモック
        $mockUser = \Mockery::mock(SocialiteUser::class);
        $mockUser->shouldReceive('getId')->andReturn('google_123');
        $mockUser->shouldReceive('getName')->andReturn('Updated Name');
        $mockUser->shouldReceive('getEmail')->andReturn('updated@example.com');
        $mockUser->shouldReceive('getAvatar')->andReturn('https://example.com/new-avatar.jpg');
        $mockUser->shouldReceive('getAccessToken')->andReturn('new_access_token');
        $mockUser->token = 'new_access_token';
        $mockUser->expiresIn = 3600;
        $mockUser->refreshToken = 'new_refresh_token';

        Socialite::shouldReceive('driver->stateless->user')->andReturn($mockUser);

        $response = $this->get('/auth/google/callback');

        // ユーザー情報が更新されたことを確認
        // プライバシー保護のため、Googleの氏名ではなくメールアドレスの@前を使用
        $this->assertDatabaseHas('users', [
            'google_id' => 'google_123',
            'name' => 'updated',
            'email' => 'updated@example.com',
        ]);

        // ユーザーが新規作成されていないことを確認（1人のまま）
        $this->assertEquals(1, User::count());

        $response->assertRedirect('/manage');
        $this->assertAuthenticated();
    }

    public function test_callback_stores_tokens_correctly(): void
    {
        // Socialiteをモック
        $mockUser = \Mockery::mock(SocialiteUser::class);
        $mockUser->shouldReceive('getId')->andReturn('google_123');
        $mockUser->shouldReceive('getName')->andReturn('Test User');
        $mockUser->shouldReceive('getEmail')->andReturn('test@example.com');
        $mockUser->shouldReceive('getAvatar')->andReturn('https://example.com/avatar.jpg');
        $mockUser->shouldReceive('getAccessToken')->andReturn('test_access_token');
        $mockUser->token = 'test_access_token';
        $mockUser->expiresIn = 3600;
        $mockUser->refreshToken = 'test_refresh_token';

        Socialite::shouldReceive('driver->stateless->user')->andReturn($mockUser);

        $this->get('/auth/google/callback');

        $user = User::where('google_id', 'google_123')->first();

        // トークンが正しく保存されていることを確認
        $this->assertNotNull($user->google_token);
        $this->assertIsArray($user->google_token);
        $this->assertEquals('test_access_token', $user->google_token['access_token']);
        $this->assertEquals(3600, $user->google_token['expires_in']);
        $this->assertArrayHasKey('created', $user->google_token);

        // リフレッシュトークンが保存されていることを確認
        $this->assertEquals('test_refresh_token', $user->google_refresh_token);
    }

    public function test_callback_handles_exception(): void
    {
        // Socialiteが例外をスローするようにモック
        Socialite::shouldReceive('driver->stateless->user')
            ->andThrow(new \Exception('OAuth error'));

        // Logファサードをモック
        Log::shouldReceive('error')
            ->once()
            ->with(
                \Mockery::pattern('/Google OAuth callback error/'),
                \Mockery::type('array')
            );

        $response = $this->get('/auth/google/callback');

        // エラーメッセージと共にログイン画面にリダイレクトされることを確認
        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('email');

        // ユーザーが作成されていないことを確認
        $this->assertEquals(0, User::count());
        $this->assertGuest();
    }

    public function test_callback_logs_user_in(): void
    {
        // Socialiteをモック
        $mockUser = \Mockery::mock(SocialiteUser::class);
        $mockUser->shouldReceive('getId')->andReturn('google_123');
        $mockUser->shouldReceive('getName')->andReturn('Test User');
        $mockUser->shouldReceive('getEmail')->andReturn('test@example.com');
        $mockUser->shouldReceive('getAvatar')->andReturn('https://example.com/avatar.jpg');
        $mockUser->shouldReceive('getAccessToken')->andReturn('test_access_token');
        $mockUser->token = 'test_access_token';
        $mockUser->expiresIn = 3600;
        $mockUser->refreshToken = 'test_refresh_token';

        Socialite::shouldReceive('driver->stateless->user')->andReturn($mockUser);

        $response = $this->get('/auth/google/callback');

        // ユーザーがログインしていることを確認
        $this->assertAuthenticated();

        $user = User::where('google_id', 'google_123')->first();
        $this->assertEquals($user->id, auth()->id());
    }

    public function test_callback_links_existing_email_user(): void
    {
        // 通常登録（google_idなし）の既存ユーザーを作成
        $existingUser = User::factory()->create([
            'google_id' => null,
            'name' => 'Old Name',
            'email' => 'existing@example.com',
            'email_verified_at' => now(),
        ]);

        // Socialiteをモック（同じメールアドレスでGoogle認証）
        $mockUser = \Mockery::mock(SocialiteUser::class);
        $mockUser->shouldReceive('getId')->andReturn('google_new_123');
        $mockUser->shouldReceive('getName')->andReturn('Google Name');
        $mockUser->shouldReceive('getEmail')->andReturn('existing@example.com');
        $mockUser->shouldReceive('getAvatar')->andReturn('https://example.com/avatar.jpg');
        $mockUser->shouldReceive('getAccessToken')->andReturn('test_access_token');
        $mockUser->token = 'test_access_token';
        $mockUser->expiresIn = 3600;
        $mockUser->refreshToken = 'test_refresh_token';

        Socialite::shouldReceive('driver->stateless->user')->andReturn($mockUser);

        $response = $this->get('/auth/google/callback');

        // 既存ユーザーにgoogle_idが紐付けられたことを確認
        $this->assertDatabaseHas('users', [
            'id' => $existingUser->id,
            'google_id' => 'google_new_123',
            'email' => 'existing@example.com',
        ]);

        // 新規ユーザーが作成されていないことを確認
        $this->assertEquals(1, User::count());

        // ログインしていることを確認
        $response->assertRedirect('/manage');
        $this->assertAuthenticated();
        $this->assertEquals($existingUser->id, auth()->id());
    }
}
