<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * ローカル開発用ログイン: ルート登録層
 *
 * routes/web.php はアプリのブート時に評価されるため、config() や app['env'] を
 * ブート後に書き換えても登録条件には効かない。ここでは $_SERVER を
 * parent::setUp() の前に差し替えて、登録条件そのものを検証する。
 *
 * コントローラ内のガードは DevLoginTest で検証する（あちらはルートを明示登録して
 * 登録条件を迂回する）。両者を1つのクラスに混ぜると、片方のガードを削除しても
 * もう片方の理由で404になり、テストが緑のまま通ってしまう。
 */
class DevLoginRouteRegistrationTest extends TestCase
{
    /**
     * 環境変数を差し替えてアプリを起動する
     *
     * DEV_LOGIN_ENABLED は unset ではなく明示的に値を置くこと。
     * Dotenv の safeLoad は既存の環境変数を上書きしないため、unset にすると
     * 開発者の .env の値が読み込まれ、有効化している人の環境でだけ結果が変わる。
     *
     * APP_ENV / DEV_LOGIN_ENABLED 以外は触らないこと（DB_* を上書きすると
     * phpunit.xml の sqlite ではなく mysql を掴んで落ちる）。
     *
     * refreshApplication() で in-memory DB が作り直されるため、
     * RefreshDatabase ではなくここで明示的にマイグレートする。
     */
    private function boot(string $appEnv, string $enabled, bool $withDatabase = false): void
    {
        $_SERVER['APP_ENV'] = $appEnv;
        $_SERVER['DEV_LOGIN_ENABLED'] = $enabled;

        $this->refreshApplication();

        if ($withDatabase) {
            // APP_ENV=production では確認を求められて中断するため --force が必要
            $this->artisan('migrate', ['--force' => true]);
        }
    }

    protected function tearDown(): void
    {
        unset($_SERVER['APP_ENV'], $_SERVER['DEV_LOGIN_ENABLED']);

        parent::tearDown();
    }

    /**
     * local かつ有効ならルートが登録され、ログインできること
     */
    public function test_route_is_registered_in_local_when_enabled(): void
    {
        $this->boot('local', 'true', withDatabase: true);

        $user = User::factory()->create();

        $this->assertTrue(Route::has('dev.login'), 'local かつ有効なのにルートが登録されていない');
        $this->get('/dev-login')->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    /**
     * local でも無効ならルートが登録されないこと
     *
     * これが「既定では無効」を検証するテスト。
     */
    public function test_route_is_not_registered_when_disabled(): void
    {
        $this->boot('local', 'false', withDatabase: true);

        User::factory()->create();

        $this->assertFalse(Route::has('dev.login'), '無効なのにルートが登録されている');
        $this->get('/dev-login')->assertNotFound();
        $this->assertGuest();
    }

    /**
     * 有効でも local 以外ならルートが登録されないこと
     */
    public function test_route_is_not_registered_outside_local(): void
    {
        $this->boot('production', 'true', withDatabase: true);

        User::factory()->create();

        $this->assertFalse(Route::has('dev.login'), 'local 以外なのにルートが登録されている');
        $this->get('/dev-login')->assertNotFound();
        $this->assertGuest();
    }

    /**
     * env の値がどう解釈されるかを固定する
     *
     * 注意: これは「truthy な文字列なら安全」という意味ではない。
     * '1' / 'on' / 'yes' はいずれも有効化する。無効にしたいときは
     * 'false' / '0' / 空 のいずれかにすること。
     *
     * @dataProvider enabledValueProvider
     */
    public function test_enabled_flag_interpretation(string $envValue, bool $expected): void
    {
        $this->boot('local', $envValue);

        $this->assertSame(
            $expected,
            config('dev_login.enabled'),
            "DEV_LOGIN_ENABLED='{$envValue}' の解釈が変わっている"
        );
        $this->assertSame($expected, Route::has('dev.login'));
    }

    public static function enabledValueProvider(): array
    {
        return [
            "'true' は有効" => ['true', true],
            "'1' は有効" => ['1', true],
            "'on' は有効" => ['on', true],
            "'yes' は有効" => ['yes', true],
            "'false' は無効" => ['false', false],
            "'0' は無効" => ['0', false],
            '空文字は無効' => ['', false],
        ];
    }
}
