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
            'nested' => [
                'access_token' => 'token-value',
                'bvn' => '12345678901',
                'email' => 'user@example.com',
            ],
        ]);

        $this->assertSame(42, $redacted['user_id']);
        $this->assertSame('[REDACTED]', $redacted['password']);
        $this->assertSame('[REDACTED]', $redacted['authorization']);
        $this->assertSame('[REDACTED]', $redacted['nested']['access_token']);
        $this->assertSame('[REDACTED]', $redacted['nested']['bvn']);
        $this->assertSame('user@example.com', $redacted['nested']['email']);
    }
}
