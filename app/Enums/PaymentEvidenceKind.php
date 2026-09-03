<?php

namespace App\Enums;

enum PaymentEvidenceKind: string
{
    case Receipt = 'receipt';
    case TransferConfirmation = 'transfer_confirmation';
    case TransactionScreenshot = 'transaction_screenshot';
    case TransactionReference = 'transaction_reference';
    case Other = 'other';

    public function requiresFile(): bool
    {
        return $this !== self::TransactionReference;
    }

    public function requiresReference(): bool
    {
        return $this === self::TransactionReference;
    }
}
