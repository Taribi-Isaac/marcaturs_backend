<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\Api\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Two independent Admin browser sessions. Each request carries only its own cookie jar.
 */
class AdminPasswordChangeSessionTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_ORIGIN = 'https://admin.marcaturshub.com.ng';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sanctum.stateful' => ['admin.marcaturshub.com.ng'],
            'security.admin_origins' => [self::ADMIN_ORIGIN],
            'cors.allowed_origins' => [self::ADMIN_ORIGIN],
        ]);

        // CSRF verification is skipped while the environment is "testing".
        $this->app['env'] = 'local';
    }

    public function test_password_change_rejects_the_other_admin_browser_session(): void
    {
        $admin = User::factory()->admin()->create([
            'email' => 'admin-s02@example.com',
            'password' => 'password123',
        ]);

        $sessionA = $this->login('admin-s02@example.com', 'password123');
        $sessionB = $this->login('admin-s02@example.com', 'password123');

        $sessionCookie = (string) config('session.cookie');
        $this->assertNotSame($sessionA[$sessionCookie], $sessionB[$sessionCookie]);

        $this->callAs($sessionA, 'GET', '/api/v1/admin/overview')->assertOk();
        $this->callAs($sessionB, 'GET', '/api/v1/admin/overview')->assertOk();

        $changed = $this->callAs($sessionA, 'POST', '/api/v1/auth/change-password', [
            'current_password' => 'password123',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ]);
        $changed->assertOk()
            ->assertJsonPath('data.message', 'Your password has been changed.');
        $sessionA = $this->absorb($sessionA, $changed);

        $admin->refresh();
        $this->assertTrue(Hash::check('new-password-456', $admin->password));
        $this->assertFalse(Hash::check('password123', $admin->password));
        $this->assertFalse(str_contains($admin->password, 'new-password-456'));

        $this->callAs($sessionA, 'GET', '/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $admin->id);
        $this->callAs($sessionA, 'GET', '/api/v1/admin/overview')->assertOk();

        $this->callAs($sessionB, 'GET', '/api/v1/admin/overview')
            ->assertStatus(401)
            ->assertJsonPath('error.code', ApiErrorCode::UNAUTHENTICATED);

        $this->attemptLogin('admin-s02@example.com', 'password123')
            ->assertStatus(401)
            ->assertJsonPath('error.code', ApiErrorCode::UNAUTHENTICATED);
        $this->attemptLogin('admin-s02@example.com', 'new-password-456')
            ->assertOk()
            ->assertJsonPath('data.user.id', $admin->id);
    }

    /**
     * @return array<string, string>
     */
    private function login(string $email, string $password): array
    {
        $jar = [];
        $csrf = $this->callAs($jar, 'GET', '/sanctum/csrf-cookie');
        $csrf->assertNoContent();
        $jar = $this->absorb($jar, $csrf);

        $login = $this->callAs($jar, 'POST', '/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);
        $login->assertOk()->assertJsonMissingPath('data.token');

        return $this->absorb($jar, $login);
    }

    private function attemptLogin(string $email, string $password): TestResponse
    {
        $jar = [];
        $csrf = $this->callAs($jar, 'GET', '/sanctum/csrf-cookie');
        $jar = $this->absorb($jar, $csrf);

        return $this->callAs($jar, 'POST', '/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);
    }

    /**
     * @param  array<string, string>  $jar
     * @param  array<string, mixed>  $data
     */
    private function callAs(array $jar, string $method, string $uri, array $data = []): TestResponse
    {
        $headers = [
            'Origin' => self::ADMIN_ORIGIN,
            'Referer' => self::ADMIN_ORIGIN.'/',
        ];

        if (isset($jar['XSRF-TOKEN'])) {
            $headers['X-XSRF-TOKEN'] = $jar['XSRF-TOKEN'];
        }

        $this->flushHeaders();
        $this->defaultCookies = [];
        $this->unencryptedCookies = $jar;
        $this->app['auth']->forgetGuards();
        $this->app['session.store']->flush();

        $response = $this->withCredentials()->withHeaders($headers)->json($method, $uri, $data);

        $this->app['auth']->forgetGuards();

        return $response;
    }

    /**
     * @param  array<string, string>  $jar
     * @return array<string, string>
     */
    private function absorb(array $jar, TestResponse $response): array
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->isCleared()) {
                unset($jar[$cookie->getName()]);
            } else {
                $jar[$cookie->getName()] = (string) $cookie->getValue();
            }
        }

        return $jar;
    }
}
