<?php

namespace Tests\Unit;

use Tests\TestCase;

class StagingConfigFoundationTest extends TestCase
{
    public function test_reverb_allowed_origins_are_configurable(): void
    {
        config()->set('reverb.apps.apps', [[
            'allowed_origins' => array_values(array_filter(array_map(
                static fn (string $origin): string => trim($origin),
                explode(',', 'https://app.staging.example.com, https://admin.staging.example.com'),
            ))),
        ]]);

        $origins = config('reverb.apps.apps.0.allowed_origins');

        $this->assertSame([
            'https://app.staging.example.com',
            'https://admin.staging.example.com',
        ], $origins);
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
