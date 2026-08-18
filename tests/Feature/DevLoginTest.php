<?php

namespace Tests\Feature;

use App\Http\Controllers\DevLoginController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ローカル開発用ログイン: コントローラのガード層
 *
 * routes/web.php のルート登録は条件付きなので、そのままでは「ルートが無いから404」
 * になり、コントローラ内のガードを1つも通らない。それではガードを削除しても
 * テストが緑のまま通ってしまうため、ここではルートを明示登録して
 * 登録条件を迂回し、ガード自体を1つずつ検証する。
 *
 * ルート登録条件そのものは DevLoginRouteRegistrationTest で検証する。
 */
class DevLoginTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 登録条件を迂回してルートを登録する
     */
    private function registerRoute(): void
    {
        // web グループを付けないとセッションが開始されず、
        // ログイン成功時の session()->regenerate() で落ちる
        $this->app['router']->middleware('web')
            ->get('/dev-login', DevLoginController::class)
            ->name('dev.login');
        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    /**
     * local 環境で有効化した状態にする
     */
    private function enableInLocal(): void
    {
        $this->app['env'] = 'local';
        config()->set('dev_login.enabled', true);
    }

    /**
     * 設定キャッシュ・ルートキャッシュがあると見せかける
     *
     * Application::normalizeCachePath() が呼び出しごとに Env を読むため、
     * $_SERVER 経由で差し替えられる。実際に bootstrap/cache/config.php を
     * 置くとテストスイート全体が焼かれた DB 設定で動いてしまうので使えない。
     *
     * @param  'APP_CONFIG_CACHE'|'APP_ROUTES_CACHE'  $key
     */
    private function withCachePresent(string $key, callable $callback): void
    {
        $probe = base_path('bootstrap/cache/__devlogin_probe.php');
        file_put_contents($probe, '<?php return [];');
        $_SERVER[$key] = 'bootstrap/cache/__devlogin_probe.php';

        try {
            $callback();
        } finally {
            unset($_SERVER[$key]);
            @unlink($probe);
        }
    }

    /**
     * enabled が false なら 404 であること
     *
     * このテストは abort_unless(config('dev_login.enabled') === true) を守る。
     * ルートを明示登録し APP_ENV も local にしているので、enabled ガードを
     * 削除すると必ず落ちる。
     */
    public function test_disabled_when_flag_is_false(): void
    {
        User::factory()->create();

        $this->registerRoute();
        $this->app['env'] = 'local';
        config()->set('dev_login.enabled', false);

        $this->get('/dev-login')->assertNotFound();
        $this->assertGuest();
    }

    /**
     * 有効化しても local 以外では 404 であること
     *
     * このテストは abort_unless(app()->environment('local')) を守る。
     */
    public function test_not_available_outside_local(): void
    {
        User::factory()->create();

        $this->registerRoute();
        // APP_ENV は testing のまま
        config()->set('dev_login.enabled', true);

        $this->get('/dev-login')->assertNotFound();
        $this->assertGuest();
    }

    /**
     * 設定キャッシュがあると 404 であること
     *
     * キャッシュされた設定は .env を読まずに使われるため、enabled と APP_ENV の
     * 2つのガードは同時に貫通しうる。キャッシュの存在自体で閉じる。
     */
    public function test_not_available_when_configuration_is_cached(): void
    {
        User::factory()->create();

        $this->registerRoute();
        $this->enableInLocal();

        $this->withCachePresent('APP_CONFIG_CACHE', function () {
            $this->assertTrue($this->app->configurationIsCached(), '設定キャッシュありの状態を作れていない');
            $this->get('/dev-login')->assertNotFound();
            $this->assertGuest();
        });
    }

    /**
     * ルートキャッシュがあると 404 であること
     *
     * routes/web.php の条件付き登録は route:cache 実行時に一度だけ評価され
     * 結果が焼き込まれるため、キャッシュがある環境では条件を信用できない。
     */
    public function test_not_available_when_routes_are_cached(): void
    {
        User::factory()->create();

        $this->registerRoute();
        $this->enableInLocal();

        $this->withCachePresent('APP_ROUTES_CACHE', function () {
            $this->assertTrue($this->app->routesAreCached(), 'ルートキャッシュありの状態を作れていない');
            $this->get('/dev-login')->assertNotFound();
            $this->assertGuest();
        });
    }

    /**
     * local 環境で有効化されていればログインできること
     */
    public function test_logs_in_when_enabled_in_local(): void
    {
        $user = User::factory()->create();

        $this->registerRoute();
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

        $this->registerRoute();
        $this->enableInLocal();
        config()->set('dev_login.email', 'target@example.com');

        $this->get('/dev-login')->assertRedirect();
        $this->assertAuthenticatedAs($target);
    }

    /**
     * クエリパラメータではユーザーを指定できないこと
     *
     * 指定できると、この経路が到達可能な環境で任意ユーザーになりきれてしまう
     * （登録ユーザーは全員が管理機能を使えるため影響が大きい）。
     */
    public function test_query_email_cannot_override_configured_user(): void
    {
        $configured = User::factory()->create(['email' => 'configured@example.com']);
        User::factory()->create(['email' => 'other@example.com']);

        $this->registerRoute();
        $this->enableInLocal();
        config()->set('dev_login.email', 'configured@example.com');

        $this->get('/dev-login?email=other@example.com')->assertRedirect();
        $this->assertAuthenticatedAs($configured);
    }

    /**
     * 該当ユーザーがいなければ 404 であること
     */
    public function test_not_found_when_user_missing(): void
    {
        $this->registerRoute();
        $this->enableInLocal();
        config()->set('dev_login.email', 'nobody@example.com');

        $this->get('/dev-login')->assertNotFound();
        $this->assertGuest();
    }

    /**
     * ユーザーが1人もいない場合も 404 であること
     */
    public function test_not_found_when_no_users_exist(): void
    {
        $this->registerRoute();
        $this->enableInLocal();

        $this->get('/dev-login')->assertNotFound();
        $this->assertGuest();
    }
}
