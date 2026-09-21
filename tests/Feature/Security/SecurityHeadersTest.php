<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\SetSecurityHeaders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_response_includes_baseline_security_headers(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        $this->assertFalse($response->headers->has('Strict-Transport-Security'));
        $this->assertFalse($response->headers->has('Content-Security-Policy'));
    }

    public function test_security_headers_do_not_alter_json_envelope(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'status',
                    'service',
                    'api_version',
                    'timestamp',
                    'checks',
                ],
            ]);
    }

    public function test_hsts_is_emitted_only_when_enabled_on_https(): void
    {
        config()->set('security.headers.hsts_enabled', true);
        config()->set('security.headers.hsts_max_age', 31536000);

        $middleware = new SetSecurityHeaders;
        $httpsRequest = Request::create('https://api.example.com/api/v1/health', 'GET');
        $this->assertTrue($httpsRequest->secure());

        $httpsResponse = $middleware->handle(
            $httpsRequest,
            fn () => response()->json(['success' => true]),
        );
        $this->assertSame(
            'max-age=31536000; includeSubDomains',
            $httpsResponse->headers->get('Strict-Transport-Security'),
        );

        config()->set('security.headers.hsts_enabled', false);
        $http = $this->getJson('/api/v1/health');
        $http->assertOk();
        $this->assertFalse($http->headers->has('Strict-Transport-Security'));
    }

    public function test_hsts_not_sent_on_http_even_when_enabled(): void
    {
        config()->set('security.headers.hsts_enabled', true);

        $response = $this->getJson('/api/v1/health');

        $response->assertOk();
        $this->assertFalse($response->headers->has('Strict-Transport-Security'));
    }
}
