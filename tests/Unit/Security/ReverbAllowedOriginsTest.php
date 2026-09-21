<?php

namespace Tests\Unit\Security;

use App\Support\Security\ReverbAllowedOrigins;
use Tests\TestCase;

class ReverbAllowedOriginsTest extends TestCase
{
    public function test_wildcard_is_never_accepted(): void
    {
        $this->assertSame([], ReverbAllowedOrigins::parseAndNormalize('*'));
        $this->assertSame(
            ['localhost'],
            ReverbAllowedOrigins::parseAndNormalize('*, localhost, *'),
        );
    }

    public function test_full_urls_normalize_to_hosts(): void
    {
        $this->assertSame(
            ['localhost', '127.0.0.1', 'app.staging.example.com'],
            ReverbAllowedOrigins::parseAndNormalize(
                'http://localhost:5180,http://127.0.0.1:5174,https://app.staging.example.com',
            ),
        );
    }

    public function test_host_port_entries_normalize_to_host(): void
    {
        $this->assertSame(
            ['localhost'],
            ReverbAllowedOrigins::parseAndNormalize('localhost:5180'),
        );
    }

    public function test_local_fallback_when_unset_does_not_use_wildcard(): void
    {
        $origins = ReverbAllowedOrigins::resolve('', 'local');

        $this->assertNotContains('*', $origins);
        $this->assertContains('localhost', $origins);
        $this->assertContains('127.0.0.1', $origins);
    }

    public function test_production_empty_is_fail_closed(): void
    {
        $this->assertSame([], ReverbAllowedOrigins::resolve('', 'production'));
        $this->assertSame([], ReverbAllowedOrigins::resolve('*', 'production'));
        $this->assertSame([], ReverbAllowedOrigins::resolve(null, 'staging'));
    }

    public function test_explicit_production_hosts_are_kept(): void
    {
        $this->assertSame(
            ['app.example.com', 'admin.example.com'],
            ReverbAllowedOrigins::resolve(
                'https://app.example.com,https://admin.example.com',
                'production',
            ),
        );
    }

    public function test_config_default_does_not_contain_wildcard(): void
    {
        $origins = config('reverb.apps.apps.0.allowed_origins');

        $this->assertIsArray($origins);
        $this->assertNotContains('*', $origins);
        $this->assertNotEmpty($origins); // testing env uses local fallback
    }

    public function test_accepts_configured_origin_and_rejects_others(): void
    {
        $allowed = ['localhost', 'app.staging.example.com'];

        $this->assertTrue(ReverbAllowedOrigins::acceptsOrigin('http://localhost:5180', $allowed));
        $this->assertTrue(ReverbAllowedOrigins::acceptsOrigin('https://app.staging.example.com', $allowed));
        $this->assertFalse(ReverbAllowedOrigins::acceptsOrigin('https://evil.example', $allowed));
        $this->assertFalse(ReverbAllowedOrigins::acceptsOrigin('http://localhost:5180', []));
        $this->assertFalse(ReverbAllowedOrigins::acceptsOrigin('http://localhost:5180', ['*']));
    }
}
