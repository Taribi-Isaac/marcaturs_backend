<?php

namespace App\Enums;

enum DisputeEventType: string
{
    case Created = 'dispute_created';

    case ReviewStarted = 'dispute_review_started';

    case EvidenceRequested = 'dispute_evidence_requested';

    case ReviewResumed = 'dispute_review_resumed';

    case DecisionPending = 'dispute_decision_pending';

    case Resolved = 'dispute_resolved';

    case Closed = 'dispute_closed';
}
