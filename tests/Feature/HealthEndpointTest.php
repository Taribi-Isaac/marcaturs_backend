<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_reports_operational_status_without_secrets(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.service', 'marcaturshub-api')
            ->assertJsonPath('data.api_version', 'v1')
            ->assertJsonPath('data.checks.application', 'ok')
            ->assertJsonPath('data.checks.database', 'ok')
            ->assertJsonPath('data.checks.cache', 'skipped')
            ->assertJsonMissingPath('data.database')
            ->assertJsonMissingPath('data.credentials')
            ->assertJsonMissingPath('data.app_key')
            ->assertJsonMissingPath('data.env');

        $payload = $response->json();

        $this->assertArrayNotHasKey('APP_KEY', $payload);
        $this->assertStringNotContainsStringIgnoringCase('password', json_encode($payload));
        $this->assertArrayHasKey('timestamp', $payload['data']);
    }
}
