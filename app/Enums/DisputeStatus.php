<?php

namespace App\Enums;

enum DisputeStatus: string
{
    case Submitted = 'submitted';

    case UnderReview = 'under_review';

    case EvidenceRequested = 'evidence_requested';

    case DecisionPending = 'decision_pending';

    case Resolved = 'resolved';

    case Closed = 'closed';

    public function isTerminal(): bool
    {
        return $this === self::Closed;
    }

    public function allowsPartyAttachmentUpload(): bool
    {
        return match ($this) {
            self::Submitted,
            self::UnderReview,
            self::EvidenceRequested => true,
            default => false,
        };
    }

    public function isOpen(): bool
    {
        return ! $this->isTerminal() && $this !== self::Resolved;
    }

    /**
     * Status values for which {@see isOpen()} is true.
     *
     * Derived from the enum cases so query scopes stay aligned with isOpen().
     *
     * @return list<string>
     */
    public static function openValues(): array
    {
        return array_values(array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => $status->isOpen()),
        ));
    }
}
