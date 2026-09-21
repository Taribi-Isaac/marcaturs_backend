<?php

namespace Tests\Unit\Support\Payments;

use App\Support\Payments\PaystackReturnUrl;
use Tests\TestCase;

class PaystackReturnUrlTest extends TestCase
{
    public function test_builds_certification_return_from_frontend_url(): void
    {
        config([
            'paystack.frontend_url' => 'https://app.example.test',
            'paystack.callback_url' => 'https://legacy.example/ignored',
        ]);

        $this->assertSame(
            'https://app.example.test/app/ambassador/certification/purchase/return',
            PaystackReturnUrl::certificationPurchase(),
        );
    }

    public function test_builds_campaign_return_from_frontend_url(): void
    {
        config(['paystack.frontend_url' => 'https://app.example.test']);

        $this->assertSame(
            'https://app.example.test/app/business/campaigns/42',
            PaystackReturnUrl::businessCampaign(42),
        );
    }

    public function test_falls_back_to_paystack_callback_when_frontend_url_empty(): void
    {
        config([
            'paystack.frontend_url' => '',
            'paystack.callback_url' => 'https://override.example/return',
        ]);

        $this->assertSame(
            'https://override.example/return',
            PaystackReturnUrl::forPath('/any'),
        );
    }

    public function test_returns_null_when_no_frontend_or_callback_configured(): void
    {
        config([
            'paystack.frontend_url' => null,
            'paystack.callback_url' => null,
        ]);

        $this->assertNull(PaystackReturnUrl::forPath('/any'));
    }
}
