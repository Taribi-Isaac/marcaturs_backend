<?php

namespace App\Enums;

enum DealEventType: string
{
    case Created = 'deal_created';

    case PaymentEvidenceSubmitted = 'payment_evidence_submitted';

    case PaymentConfirmed = 'payment_confirmed';

    case DealSealed = 'deal_sealed';

    case CommissionDue = 'commission_due';

    case PaymentRejected = 'payment_rejected';
}
