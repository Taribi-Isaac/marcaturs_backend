<?php

namespace Tests\Unit;

use App\Support\Security\ReverbAllowedOrigins;
use Tests\TestCase;

class StagingConfigFoundationTest extends TestCase
{
    public function test_reverb_allowed_origins_are_configurable_without_wildcard(): void
    {
        $origins = ReverbAllowedOrigins::resolve(
            'https://app.staging.example.com, https://admin.staging.example.com',
            'staging',
        );

        $this->assertSame([
            'app.staging.example.com',
            'admin.staging.example.com',
        ], $origins);
        $this->assertNotContains('*', $origins);
    }

    public function test_reverb_config_default_is_not_wildcard(): void
    {
        $origins = config('reverb.apps.apps.0.allowed_origins');

        $this->assertIsArray($origins);
        $this->assertNotContains('*', $origins);
    }

    public function test_private_disks_default_to_local_drivers(): void
    {
        $this->assertSame('local', config('filesystems.disks.sensitive.driver'));
        $this->assertSame('local', config('filesystems.disks.campaign_media.driver'));
        $this->assertSame('private', config('filesystems.disks.sensitive.visibility'));
        $this->assertSame('private', config('filesystems.disks.campaign_media.visibility'));
    }
}
