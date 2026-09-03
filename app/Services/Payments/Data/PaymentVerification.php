<?php

namespace App\Services\Payments\Data;

final readonly class PaymentVerification
{
    public function __construct(
        public string $reference,
        public string $status,
        public int $amountMinor,
        public string $currency,
        public ?string $providerReference = null,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->status === 'success';
    }
}
