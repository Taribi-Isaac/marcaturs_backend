<?php

namespace Tests\Unit;

use App\Support\Logging\RedactSensitiveContextProcessor;
use PHPUnit\Framework\TestCase;

class RedactSensitiveContextProcessorTest extends TestCase
{
    public function test_it_redacts_credentials_tokens_and_identity_fields(): void
    {
        $processor = new RedactSensitiveContextProcessor;

        $redacted = $processor->redact([
            'user_id' => 42,
            'password' => 'secret-password',
            'authorization' => 'Bearer abc.def',
            'x-paystack-signature' => 'sig',
            'nested' => [
                'access_token' => 'token-value',
                'bvn' => '12345678901',
                'email' => 'user@example.com',
                'text_value' => 'sensitive submitted text',
                'reviewer_notes' => 'internal note',
                'payment_account_identifier' => '0000000000',
                'content' => 'private chat text',
                'report_reason' => 'abuse report text',
            ],
        ]);

        $this->assertSame(42, $redacted['user_id']);
        $this->assertSame('[REDACTED]', $redacted['password']);
        $this->assertSame('[REDACTED]', $redacted['authorization']);
        $this->assertSame('[REDACTED]', $redacted['x-paystack-signature']);
        $this->assertSame('[REDACTED]', $redacted['nested']['access_token']);
        $this->assertSame('[REDACTED]', $redacted['nested']['bvn']);
        $this->assertSame('user@example.com', $redacted['nested']['email']);
        $this->assertSame('[REDACTED]', $redacted['nested']['text_value']);
        $this->assertSame('[REDACTED]', $redacted['nested']['reviewer_notes']);
        $this->assertSame('[REDACTED]', $redacted['nested']['payment_account_identifier']);
        $this->assertSame('[REDACTED]', $redacted['nested']['content']);
        $this->assertSame('[REDACTED]', $redacted['nested']['report_reason']);
    }
}
