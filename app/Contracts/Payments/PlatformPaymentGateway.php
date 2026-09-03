<?php

namespace App\Contracts\Payments;

use App\Services\Payments\Data\PaymentInitialization;
use App\Services\Payments\Data\PaymentVerification;

interface PlatformPaymentGateway
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function initialize(
        string $email,
        int $amountMinor,
        string $currency,
        string $reference,
        ?string $callbackUrl,
        array $metadata,
    ): PaymentInitialization;

    public function verify(string $reference): PaymentVerification;

    public function validWebhookSignature(string $rawBody, string $signature): bool;
}
