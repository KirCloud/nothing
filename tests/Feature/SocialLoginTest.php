<?php

namespace Tests\Feature;

use App\Jobs\SendEmailJob;
use App\Models\OAuthIdentity;
use App\Models\User;
use App\Services\AuthService;
use App\Services\SocialLoginService;
use App\Utils\CacheKey;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SocialLoginTest extends TestCase
{
    private $clientState;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'cache.default' => 'array', 'app.key' => 'synthetic-test-key',
            'v2board' => ['app_url' => 'https://panel.example.com', 'app_name' => 'Test',
                'telegram_bot_enable' => 1, 'telegram_bot_token' => 'synthetic-bot-token'],
            'services.google' => ['client_id' => 'test-client', 'client_secret' => 'test-secret',
                'redirect' => 'https://api.example.com/api/v1/passport/oauth/google/callback'],
            'services.login' => ['telegram_enabled' => true, 'telegram_bot_name' => 'example_bot', 'mail_link_enabled' => true],
            'services.captcha' => ['type' => 'cloudflare', 'site_key' => 'public-key', 'secret_key' => 'secret-key'],
        ]);
        DB::purge('sqlite');
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Schema::create('v2_user', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('uuid')->nullable();
            $table->string('token')->unique();
            $table->integer('banned')->default(0);
            $table->integer('is_admin')->default(0);
            $table->integer('is_staff')->default(0);
            $table->bigInteger('telegram_id')->nullable();
            $table->unsignedInteger('invite_user_id')->nullable();
            $table->unsignedInteger('last_login_at')->nullable();
            $table->unsignedInteger('created_at')->nullable();
            $table->unsignedInteger('updated_at')->nullable();
        });
        Schema::create('v2_invite_code', function (Blueprint $table) {
            $table->increments('id');
            $table->string('code')->unique();
            $table->unsignedInteger('user_id')->nullable();
            $table->integer('status')->default(0);
            $table->unsignedInteger('created_at')->nullable();
            $table->unsignedInteger('updated_at')->nullable();
        });
        require_once base_path('database/migrations/2026_11_11_000000_create_oauth_identities_table.php');
        (new \CreateOauthIdentitiesTable())->up();
        Bus::fake();
        $this->clientState = str_repeat('a', 64);
    }

    private function user(array $attributes = []): User
    {
        return User::create(array_merge(['email' => 'existing@gmail.com', 'password' => password_hash('existing-password', PASSWORD_DEFAULT),
            'token' => bin2hex(random_bytes(16))], $attributes));
    }

    private function adminConfigUrl(string $action = 'fetch'): string
    {
        foreach ($this->app['router']->getRoutes() as $route) {
            if (substr($route->getActionName(), -strlen('ConfigController@' . $action)) === 'ConfigController@' . $action) {
                return '/' . $route->uri();
            }
        }
        $this->fail('Admin config route not found');
    }

    private function adminHeaders(bool $admin = true): array
    {
        $user = $this->user(['is_admin' => $admin ? 1 : 0, 'email' => $admin ? 'admin@example.com' : 'member@example.com']);
        $auth = (new AuthService($user))->generateAuthData(\Illuminate\Http\Request::create('/'));
        return ['Authorization' => $auth['auth_data']];
    }

    public function testGoogleSettingsRequireAnAdminAndNeverReturnTheSecret(): void
    {
        config(['v2board.google_client_id' => 'panel-client', 'v2board.google_client_secret' => 'panel-secret',
            'v2board.google_oauth_enable' => 0]);
        $this->getJson($this->adminConfigUrl() . '?key=google')->assertForbidden();
        $this->getJson($this->adminConfigUrl() . '?key=google', $this->adminHeaders(false))->assertForbidden();
        $response = $this->getJson($this->adminConfigUrl() . '?key=google', $this->adminHeaders())
            ->assertOk()->assertJsonPath('data.google.google_client_id', 'panel-client')
            ->assertJsonPath('data.google.google_client_secret_configured', true)
            ->assertJsonPath('data.google.google_oauth_enable', 0);
        $this->assertArrayNotHasKey('google_client_secret', $response->json('data.google'));
        $this->assertStringNotContainsString('panel-secret', $response->getContent());
        $this->getJson('/api/v1/guest/comm/config')->assertOk()->assertJsonPath('data.auth_providers.google', false)
            ->assertDontSee('panel-secret')->assertDontSee('test-secret');
    }

    public function testGoogleSettingsValidateCredentialsAndCallbackUrls(): void
    {
        config(['services.google.client_id' => null, 'services.google.client_secret' => null]);
        $headers = $this->adminHeaders();
        $this->postJson($this->adminConfigUrl('save'), ['google_oauth_enable' => 1], $headers)
            ->assertStatus(422)->assertJsonValidationErrors(['google_client_id', 'google_client_secret']);
        foreach (['https://panel.example.com/#/login', 'https://panel.example.com/?next=evil', 'https://user:password@panel.example.com'] as $url) {
            $this->postJson($this->adminConfigUrl('save'), ['oauth_frontend_url' => $url], $headers)
                ->assertStatus(422)->assertJsonValidationErrors('oauth_frontend_url');
        }
        $this->postJson($this->adminConfigUrl('save'), ['google_redirect_uri' => 'ftp://api.example.com/callback'], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('google_redirect_uri');
    }

    public function testSavingGoogleSettingsRetainsBlankSecretAndOtherPanelSettings(): void
    {
        config(['v2board.google_client_secret' => 'saved-secret', 'v2board.server_token' => 'unchanged-node-token']);
        $saved = null;
        File::shouldReceive('put')->once()->withArgs(function ($path, $content) use (&$saved) {
            $this->assertSame(base_path('config/v2board.php'), $path);
            $temporary = tempnam(sys_get_temp_dir(), 'google-config-test-');
            try { file_put_contents($temporary, $content); $saved = require $temporary; }
            finally { unlink($temporary); }
            return true;
        })->andReturn(1);
        Artisan::shouldReceive('call')->once()->with('config:cache')->andReturn(0);
        $this->postJson($this->adminConfigUrl('save'), [
            'google_oauth_enable' => 1, 'google_client_id' => 'updated-client', 'google_client_secret' => '',
            'google_redirect_uri' => 'https://api.example.com/api/v1/passport/oauth/google/callback',
            'oauth_frontend_url' => 'https://panel.example.com/subdir',
        ], $this->adminHeaders())->assertOk();
        $this->assertSame('saved-secret', $saved['google_client_secret']);
        $this->assertSame('unchanged-node-token', $saved['server_token']);
        $this->assertSame('updated-client', $saved['google_client_id']);
        $this->assertSame('https://panel.example.com/subdir', $saved['oauth_frontend_url']);
    }

    public function testPanelGoogleSettingsOverrideEnvironmentAndCanDisableLogin(): void
    {
        config(['v2board.google_client_id' => 'panel-client', 'v2board.google_client_secret' => 'panel-secret',
            'v2board.google_redirect_uri' => 'https://other-api.example.com/api/v1/passport/oauth/google/callback',
            'v2board.oauth_frontend_url' => 'https://panel.example.com', 'v2board.google_oauth_enable' => 1]);
        $this->user(); $this->mockGoogle([]);
        $state = $this->startGoogle();
        $this->finishGoogle($state);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://oauth2.googleapis.com/token'
                && $request['client_id'] === 'panel-client' && $request['client_secret'] === 'panel-secret'
                && $request['redirect_uri'] === 'https://other-api.example.com/api/v1/passport/oauth/google/callback';
        });
        config(['v2board.google_oauth_enable' => 0]);
        $this->getJson('/api/v1/guest/comm/config')->assertJsonPath('data.auth_providers.google', false);
        $this->postJson('/api/v1/passport/oauth/auth', ['type' => 'google', 'state' => $this->clientState])->assertNotFound();
    }

    private function startGoogle(array $data = []): string
    {
        $response = $this->postJson('/api/v1/passport/oauth/auth', array_merge([
            'type' => 'google', 'state' => $this->clientState,
            'redirect' => 'https://panel.example.com/#/oauth/callback',
        ], $data))->assertOk();
        parse_str(parse_url($response->json('data.url'), PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame((new SocialLoginService())->googleCallback(), $query['redirect_uri']);
        return $query['state'];
    }

    private function mockGoogle(array $profile): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'synthetic-access-token']),
            'openidconnect.googleapis.com/v1/userinfo' => Http::response(array_merge([
                'sub' => 'google-subject', 'email' => 'existing@gmail.com', 'email_verified' => true,
            ], $profile)),
            '*' => Http::response([], 500),
        ]);
    }

    private function finishGoogle(string $state): array
    {
        $response = $this->get('/api/v1/passport/oauth/google/callback?' . http_build_query(['state' => $state, 'code' => 'synthetic-code']))
            ->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://panel.example.com/#/oauth/callback?', $location);
        $this->assertStringNotContainsString('auth_data', $location);
        parse_str(explode('?', parse_url($location, PHP_URL_FRAGMENT), 2)[1], $query);
        return $query;
    }

    public function testGoogleLoginUsesPkceStableIdentityAndSingleUseTicket(): void
    {
        $user = $this->user();
        $this->mockGoogle([]);
        $state = $this->startGoogle();
        $query = $this->finishGoogle($state);
        $this->assertSame($this->clientState, $query['state']);
        $this->assertSame($user->id, OAuthIdentity::first()->user_id);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://oauth2.googleapis.com/token'
                && strlen($request['code_verifier']) === 64 && $request['client_secret'] === 'test-secret';
        });
        $this->postJson('/api/v1/passport/oauth/exchange', ['ticket' => $query['ticket'], 'state' => str_repeat('b', 64)])->assertForbidden();
        $response = $this->postJson('/api/v1/passport/oauth/exchange', $query)->assertOk();
        $this->assertSame($user->token, $response->json('data.token'));
        $this->assertSame($user->id, AuthService::decryptAuthData($response->json('data.auth_data'))['id']);
        $this->postJson('/api/v1/passport/oauth/exchange', $query)->assertStatus(422);
        $this->get('/api/v1/passport/oauth/google/callback?state=' . $state . '&code=again')->assertStatus(422);
    }

    public function testGoogleSubjectContinuesToIdentifyTheAccountAfterItsEmailChanges(): void
    {
        $user = $this->user(['email' => 'original@example.com']);
        OAuthIdentity::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'google-subject']);
        $this->mockGoogle(['email' => 'changed@example.com']);
        $query = $this->finishGoogle($this->startGoogle());
        $this->postJson('/api/v1/passport/oauth/exchange', $query)->assertOk()->assertJsonPath('data.token', $user->token);
        $this->assertSame(1, User::count());
    }

    public function testGoogleRegistrationPreservesInvitesAndRegistrationClosure(): void
    {
        config(['v2board.invite_force' => 1]);
        $this->mockGoogle(['email' => 'new@example.com']);
        $query = $this->finishGoogle($this->startGoogle());
        $this->assertSame('login_failed', $query['error']);
        $this->assertSame(0, User::count());
        DB::table('v2_invite_code')->insert(['code' => 'valid-invite', 'user_id' => 7, 'status' => 0]);
        $query = $this->finishGoogle($this->startGoogle(['code' => 'valid-invite']));
        $this->postJson('/api/v1/passport/oauth/exchange', $query)->assertOk();
        $this->assertSame(7, User::first()->invite_user_id);
        $this->assertSame(1, DB::table('v2_invite_code')->value('status'));
        $this->assertSame(1, OAuthIdentity::count());
        config(['v2board.invite_force' => 0, 'v2board.stop_register' => 1]);
        $this->mockGoogle(['email' => 'closed@gmail.com', 'sub' => 'another-google-id']);
        $this->assertSame('login_failed', $this->finishGoogle($this->startGoogle())['error']);
        $this->assertSame(1, User::count());
    }

    public function testUnverifiedGoogleEmailCannotLogInAndExternalEmailCannotTakeOverAnExistingAccount(): void
    {
        $this->user(['email' => 'external@example.com']);
        $this->mockGoogle(['email' => 'external@example.com']);
        $this->assertSame('login_failed', $this->finishGoogle($this->startGoogle())['error']);
        $this->mockGoogle(['email_verified' => false]);
        $this->assertSame('login_failed', $this->finishGoogle($this->startGoogle())['error']);
        $this->assertSame(0, OAuthIdentity::count());
    }

    public function testGoogleRejectsUntrustedRedirectsAndUnknownOrExpiredState(): void
    {
        Http::fake();
        $this->postJson('/api/v1/passport/oauth/auth', [
            'type' => 'google', 'state' => $this->clientState, 'redirect' => 'https://attacker.example/#/oauth/callback',
        ])->assertStatus(422);
        $this->get('/api/v1/passport/oauth/google/callback?state=' . str_repeat('c', 64) . '&code=code')->assertStatus(422);
        $state = $this->startGoogle();
        $this->travel(6)->minutes();
        $this->get('/api/v1/passport/oauth/google/callback?state=' . $state . '&code=code')->assertStatus(422);
        Http::assertNothingSent();
    }

    public function testTelegramRequiresABoundAccountAndTheOriginalClientState(): void
    {
        $user = $this->user(['telegram_id' => 123456]);
        $challenge = $this->postJson('/api/v1/passport/oauth/telegram', ['state' => $this->clientState])->assertOk()->json('data');
        $payload = ['code' => $challenge['hash'], 'state' => $this->clientState];
        $this->postJson('/api/v1/passport/oauth/telegram/check', $payload)->assertOk()->assertJsonPath('data.status', 'pending');
        app(SocialLoginService::class)->approveTelegram($challenge['hash'], 123456);
        $this->postJson('/api/v1/passport/oauth/telegram/check', array_merge($payload, ['state' => str_repeat('b', 64)]))->assertStatus(422);
        $this->postJson('/api/v1/passport/oauth/telegram/check', $payload)->assertOk()->assertJsonPath('data.token', $user->token);
        $this->postJson('/api/v1/passport/oauth/telegram/check', $payload)->assertStatus(422);
    }

    public function testEmailLinkReusesTheMailTemplateAndDoesNotRevealAccountExistence(): void
    {
        $this->user();
        $payload = ['email' => 'existing@gmail.com', 'state' => $this->clientState];
        $this->postJson('/api/v1/passport/auth/loginWithMailLink', $payload)->assertOk()->assertExactJson(['data' => true]);
        $this->postJson('/api/v1/passport/auth/loginWithMailLink', array_merge($payload, ['email' => 'missing@example.com']))
            ->assertOk()->assertExactJson(['data' => true]);
        Bus::assertDispatchedTimes(SendEmailJob::class, 1);
        Bus::assertDispatched(SendEmailJob::class, function ($job) {
            $property = new \ReflectionProperty($job, 'params');
            $property->setAccessible(true);
            $params = $property->getValue($job);
            $this->assertSame('mailLogin', $params['template_name']);
            parse_str(explode('?', parse_url($params['template_value']['link'], PHP_URL_FRAGMENT), 2)[1], $query);
            $this->postJson('/api/v1/passport/oauth/exchange', $query)->assertOk();
            $this->postJson('/api/v1/passport/oauth/exchange', $query)->assertStatus(422);
            return true;
        });
    }

    public function testBannedAccountsCannotRedeemAnAlreadyIssuedLoginTicket(): void
    {
        $user = $this->user();
        $ticket = app(SocialLoginService::class)->issueTicket($user, $this->clientState);
        $user->banned = 1;
        $user->save();
        $this->postJson('/api/v1/passport/oauth/exchange', ['ticket' => $ticket, 'state' => $this->clientState])->assertForbidden();
    }

    public function testTurnstileIsVerifiedOnTheServerAndNormalRegistrationKeepsStringCodes(): void
    {
        config(['v2board.recaptcha_enable' => 1, 'v2board.email_verify' => 1]);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);
        $email = 'new@example.com';
        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', $email), '001234', 300);
        $payload = ['email' => $email, 'password' => 'valid-password', 'email_code' => '001234', 'recaptcha_data' => 'turnstile-token'];
        $this->postJson('/api/v1/passport/auth/register', $payload)->assertOk();
        $this->assertFalse(Cache::has(CacheKey::get('EMAIL_VERIFY_CODE', $email)));
        Http::assertSent(fn ($request) => $request->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
            && $request['response'] === 'turnstile-token' && $request['secret'] === 'secret-key');
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false, 'error-codes' => ['timeout-or-duplicate']])]);
        $payload['email'] = 'replayed@example.com';
        $this->postJson('/api/v1/passport/auth/register', $payload)->assertStatus(422);
        $this->postJson('/api/v1/passport/comm/sendEmailVerify', $payload)->assertStatus(422);
        $this->postJson('/api/v1/passport/auth/register', array_merge($payload, ['recaptcha_data' => '']))->assertStatus(422);
        $this->assertSame(1, User::count());
    }

    public function testGuestConfigAdvertisesOnlyConfiguredProvidersAndNeverSecrets(): void
    {
        $response = $this->getJson('/api/v1/guest/comm/config')->assertOk();
        $response->assertJsonPath('data.auth_providers.google', true)->assertJsonPath('data.captcha_type', 'cloudflare');
        $this->assertStringNotContainsString('test-secret', $response->getContent());
        $this->assertStringNotContainsString('secret-key', $response->getContent());
        config(['services.google.client_secret' => null, 'services.login.telegram_enabled' => false, 'services.login.mail_link_enabled' => false]);
        $this->getJson('/api/v1/guest/comm/config')->assertOk()->assertJsonPath('data.auth_providers', [
            'google' => false, 'telegram' => false, 'email_link' => false,
        ]);
    }
}
