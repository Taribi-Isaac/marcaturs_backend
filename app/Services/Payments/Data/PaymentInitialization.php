<?php

namespace App\Services\Payments\Data;

final readonly class PaymentInitialization
{
    public function __construct(
        public string $reference,
        public string $authorizationUrl,
        public ?string $accessCode = null,
    ) {}
}
