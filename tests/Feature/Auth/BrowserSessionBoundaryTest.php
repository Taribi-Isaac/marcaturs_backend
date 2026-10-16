<?php

namespace Tests\Feature\Auth;

use App\Enums\AdminStaffRole;
use App\Models\Conversation;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * End-to-end browser behaviour for the two first-party SPAs (Sanctum stateful
 * session + CSRF) versus non-browser Bearer clients, and the Admin origin boundary.
 */
class BrowserSessionBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private const APP_ORIGIN = 'https://app.marcaturshub.com.ng';

    private const ADMIN_ORIGIN = 'https://admin.marcaturshub.com.ng';

    /** @var array<string, string> */
    private array $jar = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sanctum.stateful' => ['app.marcaturshub.com.ng', 'admin.marcaturshub.com.ng'],
            'security.admin_origins' => [self::ADMIN_ORIGIN],
            'cors.allowed_origins' => [self::APP_ORIGIN, self::ADMIN_ORIGIN],
        ]);

        // CSRF verification is skipped while the environment is "testing"; run as a real deployment would.
        $this->app['env'] = 'local';

        Notification::fake();
    }

    public function test_admin_control_browser_login_uses_the_session_and_returns_no_bearer_token(): void
    {
        $admin = User::factory()->admin()->create(['password' => 'password123']);

        $login = $this->browserLogin(self::ADMIN_ORIGIN, $admin->email);

        $login->assertOk()
            ->assertJsonPath('data.user.id', $admin->id)
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.token_type');
        $this->assertSame(['user'], array_keys($login->json('data')));
        $this->assertStringNotContainsString('Bearer', (string) $login->getContent());
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $sessionCookie = collect($login->headers->getCookies())->first(fn ($c) => $c->getName() === config('session.cookie'));
        $this->assertNotNull($sessionCookie);
        $this->assertTrue($sessionCookie->isHttpOnly());

        $this->browser('GET', '/api/v1/admin/overview', self::ADMIN_ORIGIN)->assertOk();
        $this->browser('GET', '/api/v1/auth/me', self::ADMIN_ORIGIN)->assertOk()->assertJsonPath('data.id', $admin->id);
    }

    public function test_participant_browser_login_and_registration_return_no_bearer_token(): void
    {
        $business = User::factory()->business()->create(['password' => 'password123']);

        $this->browserLogin(self::APP_ORIGIN, $business->email)
            ->assertOk()
            ->assertJsonMissingPath('data.token');
        $this->browser('GET', '/api/v1/verification/status', self::APP_ORIGIN)->assertOk();

        $this->jar = [];
        $this->browser('GET', '/sanctum/csrf-cookie', self::APP_ORIGIN)->assertNoContent();
        $this->browser('POST', '/api/v1/auth/register', self::APP_ORIGIN, [
            'name' => 'Browser Ambassador',
            'email' => 'browser-ambassador@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'AMBASSADOR',
        ])->assertCreated()
            ->assertJsonMissingPath('data.token')
            ->assertJsonPath('data.user.email_verified_at', null);

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->browser('GET', '/api/v1/auth/me', self::APP_ORIGIN)->assertOk()->assertJsonPath('data.email', 'browser-ambassador@example.com');
        $this->browser('GET', '/api/v1/ambassadors/me', self::APP_ORIGIN)
            ->assertStatus(403)
            ->assertJsonPath('error.message', 'Please verify your email address to continue.');
    }

    public function test_an_admin_signing_in_on_the_participant_app_receives_no_replayable_credential(): void
    {
        $admin = User::factory()->admin()->create(['password' => 'password123']);

        $login = $this->browserLogin(self::APP_ORIGIN, $admin->email)->assertOk();

        $this->assertNull($login->json('data.token'));
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->browser('GET', '/api/v1/admin/overview', self::APP_ORIGIN)
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);
        $this->browser('GET', '/api/v1/admin/users', self::APP_ORIGIN)->assertStatus(403);
    }

    public function test_non_browser_clients_still_receive_and_use_a_bearer_token(): void
    {
        $admin = User::factory()->admin()->create(['password' => 'password123']);
        $business = User::factory()->business()->create(['password' => 'password123']);

        $adminLogin = $this->bearer('POST', '/api/v1/auth/login', null, [
            'email' => $admin->email,
            'password' => 'password123',
        ])->assertOk();
        $adminLogin->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.token_type')
            ->assertJsonPath('data.user.id', $admin->id);
        $this->assertSame(['user'], array_keys($adminLogin->json('data')));
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $businessToken = $this->tokenLogin($business->email);
        $this->bearer('GET', '/api/v1/auth/me', $businessToken)->assertOk()->assertJsonPath('data.id', $business->id);
        $this->bearer('GET', '/api/v1/verification/status', $businessToken)->assertOk();

        $register = $this->bearer('POST', '/api/v1/auth/register', null, [
            'name' => 'Api Business',
            'email' => 'api-business@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'BUSINESS',
        ])->assertCreated()->assertJsonPath('data.token_type', 'Bearer');
        $this->assertIsString($register->json('data.token'));
    }

    public function test_an_admin_bearer_token_cannot_authorize_admin_routes_regardless_of_origin(): void
    {
        $admin = User::factory()->admin()->create(['password' => 'password123']);
        // Pre-existing PAT (e.g. issued before MH-SEC-012) must still be rejected on Admin routes.
        $token = $admin->createToken('auth')->plainTextToken;

        foreach ([null, self::ADMIN_ORIGIN, self::APP_ORIGIN, 'null', 'https://evil.example', 'https://admin.marcaturshub.com.ng.evil.example', 'http://admin.marcaturshub.com.ng', ''] as $origin) {
            $headers = $origin === null ? [] : ['Origin' => $origin];
            $this->bearer('GET', '/api/v1/admin/overview', $token, [], $headers)
                ->assertStatus(403)
                ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN)
                ->assertJsonMissingPath('data');
            $this->bearer('GET', '/api/v1/admin/users', $token, [], $headers)
                ->assertStatus(403)
                ->assertJsonMissingPath('data');
        }

        $this->browser('GET', '/sanctum/csrf-cookie', self::APP_ORIGIN)->assertNoContent();
        $stolen = ['Authorization' => 'Bearer '.$token];
        $this->browser('GET', '/api/v1/admin/users', self::APP_ORIGIN, [], $stolen)->assertStatus(403);
        $this->browser('POST', '/api/v1/admin/categories', self::APP_ORIGIN, ['name' => 'Injected Category'], $stolen)
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);
        $this->assertDatabaseMissing('categories', ['name' => 'Injected Category']);

        // Non-Admin PAT behaviour is unchanged.
        $business = User::factory()->business()->create(['password' => 'password123']);
        $businessToken = $this->tokenLogin($business->email);
        $this->bearer('GET', '/api/v1/auth/me', $businessToken)->assertOk();
    }

    public function test_an_admin_browser_session_cannot_be_used_from_the_participant_origin(): void
    {
        $admin = User::factory()->admin()->create(['password' => 'password123']);
        $this->browserLogin(self::ADMIN_ORIGIN, $admin->email)->assertOk();

        $this->browser('GET', '/api/v1/admin/overview', self::APP_ORIGIN)->assertStatus(403);
        $this->browser('POST', '/api/v1/admin/categories', self::APP_ORIGIN, ['name' => 'Cross Origin Category'])->assertStatus(403);
        $this->assertDatabaseMissing('categories', ['name' => 'Cross Origin Category']);

        // Adding a stolen-looking Bearer header to the cookie request still resolves the session, which stays origin-bound.
        $this->browser('GET', '/api/v1/admin/overview', self::APP_ORIGIN, [], ['Authorization' => 'Bearer 1|not-a-real-token'])
            ->assertStatus(403);

        $this->browser('GET', '/api/v1/admin/overview', self::ADMIN_ORIGIN)->assertOk();
    }

    public function test_business_and_ambassador_accounts_cannot_obtain_admin_access_by_any_client_mode(): void
    {
        foreach ([User::factory()->business(), User::factory()->ambassador()] as $factory) {
            $user = $factory->create(['password' => 'password123']);

            $this->jar = [];
            $this->browserLogin(self::ADMIN_ORIGIN, $user->email)->assertOk();
            $this->browser('GET', '/api/v1/admin/overview', self::ADMIN_ORIGIN)->assertStatus(403);
            $this->browser('POST', '/api/v1/admin/categories', self::ADMIN_ORIGIN, [
                'name' => 'Escalated Category',
                'role' => 'ADMIN',
                'staff_role' => 'SUPER_ADMIN',
            ], ['Sec-Fetch-Site' => 'same-origin', 'X-Requested-With' => 'XMLHttpRequest'])->assertStatus(403);

            $token = $this->tokenLogin($user->email);
            $this->bearer('GET', '/api/v1/admin/overview', $token)->assertStatus(403);
            $this->bearer('GET', '/api/v1/admin/overview', $token, [], ['Origin' => self::ADMIN_ORIGIN])->assertStatus(403);
            $this->bearer('GET', '/api/v1/admin/users', $token, [], ['Origin' => self::ADMIN_ORIGIN])->assertStatus(403);

            $this->assertSame($user->role, $user->fresh()->role);
        }

        $this->assertDatabaseMissing('categories', ['name' => 'Escalated Category']);
        $this->assertDatabaseCount('admin_staff_profiles', 0);
    }

    public function test_admin_access_still_requires_the_staff_permission_from_the_admin_origin(): void
    {
        $moderator = User::factory()->adminStaff(AdminStaffRole::Moderation)->create(['password' => 'password123']);

        $this->browserLogin(self::ADMIN_ORIGIN, $moderator->email)->assertOk();
        $this->browser('POST', '/api/v1/admin/categories', self::ADMIN_ORIGIN, ['name' => 'Moderator Category'])->assertStatus(403);

        $token = $moderator->createToken('auth')->plainTextToken;
        $this->bearer('POST', '/api/v1/admin/categories', $token, ['name' => 'Moderator Category'])->assertStatus(403);
        $this->assertDatabaseMissing('categories', ['name' => 'Moderator Category']);
    }

    public function test_invalid_credentials_are_rejected_for_both_client_modes(): void
    {
        $admin = User::factory()->admin()->create(['password' => 'password123']);

        $this->browser('GET', '/sanctum/csrf-cookie', self::ADMIN_ORIGIN)->assertNoContent();
        $this->browser('POST', '/api/v1/auth/login', self::ADMIN_ORIGIN, ['email' => $admin->email, 'password' => 'wrong-password'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', ApiErrorCode::UNAUTHENTICATED);
        $this->browser('GET', '/api/v1/admin/overview', self::ADMIN_ORIGIN)->assertStatus(401);

        $this->bearer('GET', '/api/v1/admin/overview', '1|definitely-not-valid')->assertStatus(401);
        $this->bearer('GET', '/api/v1/admin/overview', '1|definitely-not-valid', [], ['Origin' => self::ADMIN_ORIGIN])->assertStatus(401);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_csrf_protects_state_changing_cookie_requests(): void
    {
        $admin = User::factory()->admin()->create(['password' => 'password123']);

        $this->browser('GET', '/sanctum/csrf-cookie', self::ADMIN_ORIGIN)->assertNoContent();
        $this->browser('POST', '/api/v1/auth/login', self::ADMIN_ORIGIN, ['email' => $admin->email, 'password' => 'password123'], [], false)
            ->assertStatus(419);
        $this->browser('GET', '/api/v1/auth/me', self::ADMIN_ORIGIN)->assertStatus(401);

        $this->browserLogin(self::ADMIN_ORIGIN, $admin->email)->assertOk();

        $this->browser('POST', '/api/v1/admin/categories', self::ADMIN_ORIGIN, ['name' => 'No Csrf Category'], [], false)->assertStatus(419);
        $this->browser('POST', '/api/v1/admin/categories', self::ADMIN_ORIGIN, ['name' => 'Forged Csrf Category'], ['X-XSRF-TOKEN' => 'forged'], false)->assertStatus(419);
        $this->browser('POST', '/api/v1/auth/logout', self::ADMIN_ORIGIN, [], [], false)->assertStatus(419);
        $this->assertDatabaseMissing('categories', ['name' => 'No Csrf Category']);
        $this->assertDatabaseMissing('categories', ['name' => 'Forged Csrf Category']);

        $this->browser('POST', '/api/v1/admin/categories', self::ADMIN_ORIGIN, ['name' => 'Csrf Category'])->assertCreated();
        $this->browser('GET', '/api/v1/auth/me', self::ADMIN_ORIGIN)->assertOk();
    }

    public function test_browser_logout_ends_the_session_and_revokes_every_token(): void
    {
        $business = User::factory()->business()->create(['password' => 'password123']);

        $this->browserLogin(self::APP_ORIGIN, $business->email)->assertOk();
        $sessionCookies = $this->jar;
        $toolingToken = $this->tokenLogin($business->email);
        $this->bearer('GET', '/api/v1/auth/me', $toolingToken)->assertOk();

        $this->browser('POST', '/api/v1/auth/logout', self::APP_ORIGIN)->assertOk();

        $this->browser('GET', '/api/v1/auth/me', self::APP_ORIGIN)->assertStatus(401);
        $this->jar = $sessionCookies;
        $this->browser('GET', '/api/v1/auth/me', self::APP_ORIGIN)->assertStatus(401);
        $this->bearer('GET', '/api/v1/auth/me', $toolingToken)->assertStatus(401);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_bearer_logout_and_a_new_login_revoke_earlier_tokens(): void
    {
        $business = User::factory()->business()->create(['password' => 'password123']);

        $first = $this->tokenLogin($business->email);
        $second = $this->tokenLogin($business->email);
        $this->bearer('GET', '/api/v1/auth/me', $first)->assertStatus(401);
        $this->bearer('GET', '/api/v1/auth/me', $second)->assertOk();

        $this->bearer('POST', '/api/v1/auth/logout', $second)->assertOk();
        $this->bearer('GET', '/api/v1/auth/me', $second)->assertStatus(401);

        $third = $this->tokenLogin($business->email);
        $this->browserLogin(self::APP_ORIGIN, $business->email)->assertOk();
        $this->bearer('GET', '/api/v1/auth/me', $third)->assertStatus(401);
    }

    public function test_account_status_rules_are_unchanged_for_browser_sessions_and_tokens(): void
    {
        $suspended = User::factory()->business()->suspended()->create(['password' => 'password123']);
        $this->browserLogin(self::APP_ORIGIN, $suspended->email)->assertStatus(403);
        $this->browser('GET', '/api/v1/auth/me', self::APP_ORIGIN)->assertStatus(401);

        $this->jar = [];
        $restricted = User::factory()->ambassador()->restricted()->create(['password' => 'password123']);
        $this->browserLogin(self::APP_ORIGIN, $restricted->email)->assertOk();
        $this->browser('GET', '/api/v1/auth/me', self::APP_ORIGIN)->assertOk();
        $this->browser('GET', '/api/v1/ambassadors/me', self::APP_ORIGIN)
            ->assertStatus(403)
            ->assertJsonPath('error.message', 'This account is restricted.');

        $this->jar = [];
        $admin = User::factory()->admin()->create(['password' => 'password123']);
        $this->browserLogin(self::ADMIN_ORIGIN, $admin->email)->assertOk();
        $token = $admin->createToken('auth')->plainTextToken;
        $admin->forceFill(['status' => 'banned'])->save();

        $this->browser('GET', '/api/v1/admin/overview', self::ADMIN_ORIGIN)
            ->assertStatus(403)
            ->assertJsonPath('error.message', 'This account is not permitted to access the platform.');
        $this->bearer('GET', '/api/v1/admin/overview', $token)->assertStatus(403);
    }

    public function test_realtime_channel_authorization_stays_participant_only(): void
    {
        $this->useReverbChannelAuthDriver();

        $business = User::factory()->business()->create(['password' => 'password123']);
        $ambassador = User::factory()->ambassador()->create(['password' => 'password123']);
        $outsider = User::factory()->ambassador()->create(['password' => 'password123']);
        $admin = User::factory()->admin()->create(['password' => 'password123']);
        $conversationId = $this->bearer('POST', '/api/v1/conversations', $this->tokenLogin($ambassador->email), [
            'business_id' => $business->id,
        ])->assertCreated()->json('data.id');
        $this->assertNotNull(Conversation::query()->find($conversationId));
        $channel = ['socket_id' => '1234.5678', 'channel_name' => 'private-conversation.'.$conversationId];

        $this->browserLogin(self::APP_ORIGIN, $ambassador->email)->assertOk();
        $this->browser('POST', '/api/broadcasting/auth', self::APP_ORIGIN, $channel)->assertOk()->assertJsonStructure(['auth']);

        $this->jar = [];
        $this->browserLogin(self::APP_ORIGIN, $outsider->email)->assertOk();
        $this->browser('POST', '/api/broadcasting/auth', self::APP_ORIGIN, $channel)->assertStatus(403);

        $adminToken = $admin->createToken('auth')->plainTextToken;
        $this->bearer('POST', '/api/broadcasting/auth', $adminToken, $channel)->assertStatus(403);
        $this->jar = [];
        $this->browser('GET', '/sanctum/csrf-cookie', self::APP_ORIGIN)->assertNoContent();
        $this->browser('POST', '/api/broadcasting/auth', self::APP_ORIGIN, $channel, ['Authorization' => 'Bearer '.$adminToken])
            ->assertStatus(403);
    }

    public function test_credentials_are_not_logged_or_echoed_in_error_responses(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.' '.json_encode($event->context);
        });
        config(['app.debug' => false]);

        Route::middleware(['api', 'auth:sanctum'])->get('/api/v1/__mh-sec-001/explode', function (): never {
            throw new RuntimeException('Unexpected failure while handling the request.');
        });

        $admin = User::factory()->admin()->create(['password' => 'password123']);
        $this->browserLogin(self::ADMIN_ORIGIN, $admin->email)->assertOk();
        $token = $admin->createToken('auth')->plainTextToken;
        $session = $this->jar[config('session.cookie')] ?? '';
        $this->assertNotSame('', $session);

        $responses = [
            $this->bearer('GET', '/api/v1/__mh-sec-001/explode', $token)->assertStatus(500),
            $this->bearer('GET', '/api/v1/admin/overview', $token, [], ['Origin' => self::APP_ORIGIN])->assertStatus(403),
            $this->bearer('GET', '/api/v1/admin/overview', $token)->assertStatus(403),
            $this->bearer('GET', '/api/v1/admin/overview', $token.'tampered')->assertStatus(401),
            $this->browser('GET', '/api/v1/admin/overview', self::APP_ORIGIN)->assertStatus(403),
            $this->browser('POST', '/api/v1/auth/logout', self::ADMIN_ORIGIN, [], [], false)->assertStatus(419),
        ];

        $this->assertNotEmpty($logged, 'The forced server error should have been reported.');
        [, $secret] = explode('|', $token, 2);

        foreach ($responses as $response) {
            $body = (string) $response->getContent();
            $this->assertStringNotContainsString($secret, $body);
            $this->assertStringNotContainsString($session, $body);
            $this->assertStringNotContainsStringIgnoringCase('bearer', $body);
            $this->assertStringNotContainsString('Unexpected failure', $body);
        }

        foreach ($logged as $entry) {
            $this->assertStringNotContainsString($secret, $entry);
            $this->assertStringNotContainsString($session, $entry);
            $this->assertStringNotContainsStringIgnoringCase('authorization', $entry);
        }
    }

    private function browserLogin(string $origin, string $email): TestResponse
    {
        if (! isset($this->jar['XSRF-TOKEN'])) {
            $this->browser('GET', '/sanctum/csrf-cookie', $origin)->assertNoContent();
        }

        return $this->browser('POST', '/api/v1/auth/login', $origin, [
            'email' => $email,
            'password' => 'password123',
        ]);
    }

    /**
     * A first-party SPA request: Origin + Referer of the SPA, cookies from the jar and,
     * unless disabled, the X-XSRF-TOKEN header copied from the XSRF-TOKEN cookie.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    private function browser(string $method, string $uri, string $origin, array $data = [], array $headers = [], bool $withCsrfHeader = true): TestResponse
    {
        $headers = ['Origin' => $origin, 'Referer' => $origin.'/'] + $headers;

        if ($withCsrfHeader && isset($this->jar['XSRF-TOKEN']) && ! isset($headers['X-XSRF-TOKEN'])) {
            $headers['X-XSRF-TOKEN'] = $this->jar['XSRF-TOKEN'];
        }

        $response = $this->send($method, $uri, $data, $headers, $this->jar);

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->isCleared()) {
                unset($this->jar[$cookie->getName()]);
            } else {
                $this->jar[$cookie->getName()] = (string) $cookie->getValue();
            }
        }

        return $response;
    }

    /**
     * A non-browser API client: no cookies and, unless given, no Origin header.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    private function bearer(string $method, string $uri, ?string $token, array $data = [], array $headers = []): TestResponse
    {
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        return $this->send($method, $uri, $data, $headers, []);
    }

    private function tokenLogin(string $email): string
    {
        $token = $this->bearer('POST', '/api/v1/auth/login', null, [
            'email' => $email,
            'password' => 'password123',
        ])->assertOk()->json('data.token');

        $this->assertIsString($token);

        return $token;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     * @param  array<string, string>  $cookies
     */
    private function send(string $method, string $uri, array $data, array $headers, array $cookies): TestResponse
    {
        // Each simulated request starts like a fresh PHP-FPM request: no auth or session state in memory.
        $this->flushHeaders();
        $this->defaultCookies = [];
        $this->unencryptedCookies = $cookies;
        $this->app['auth']->forgetGuards();
        $this->app['session.store']->flush();

        $response = $this->withCredentials()->withHeaders($headers)->json($method, $uri, $data);

        $this->app['auth']->forgetGuards();

        return $response;
    }

    private function useReverbChannelAuthDriver(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-reverb-key',
            'broadcasting.connections.reverb.secret' => 'test-reverb-secret',
            'broadcasting.connections.reverb.app_id' => 'test-reverb-app',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 8080,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.options.useTLS' => false,
        ]);
        Broadcast::purge();
        require base_path('routes/channels.php');
    }
}
