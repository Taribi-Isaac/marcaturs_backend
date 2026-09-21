<?php

namespace App\Support\Certification;

use App\Enums\CertificationAwardStatus;
use App\Models\CertificationAward;
use Illuminate\Support\Collection;

/**
 * Read-only Ambassador profile certification representation (MH-BE-CERT-10).
 *
 * Derived from Certification Awards — never a duplicated profile/user flag.
 * Distinct from identity verification, reputation, and marketplace ranking.
 */
final class AmbassadorCertificationRepresentation
{
    public const LABEL_CERTIFIED = 'Certified MarcatursHub Ambassador';

    /**
     * @return array{
     *     is_certified: bool,
     *     label: string|null,
     *     awards: list<array<string, mixed>>
     * }
     */
    public static function forUserId(int $userId): array
    {
        /** @var Collection<int, CertificationAward> $awards */
        $awards = CertificationAward::query()
            ->where('user_id', $userId)
            ->where('status', CertificationAwardStatus::Awarded)
            ->with([
                'certificate:id,award_id,programme_name,programme_version_number',
                'programme:id,name',
                'programmeVersion:id,version_number',
            ])
            ->orderByDesc('awarded_at')
            ->orderByDesc('id')
            ->get([
                'id',
                'programme_id',
                'programme_version_id',
                'awarded_at',
                'status',
            ]);

        $items = $awards->map(static function (CertificationAward $award): array {
            $certificate = $award->certificate;

            return [
                'id' => $award->id,
                'programme_id' => $award->programme_id,
                'programme_version_id' => $award->programme_version_id,
                'programme_name' => $certificate?->programme_name
                    ?? $award->programme?->name,
                'programme_version_number' => $certificate?->programme_version_number
                    ?? $award->programmeVersion?->version_number,
                'awarded_at' => $award->awarded_at?->toIso8601String(),
                // Present when a Certificate record exists; PDF readiness is irrelevant to qualification.
                'certificate_id' => $certificate?->id,
            ];
        })->values()->all();

        $isCertified = $items !== [];

        return [
            'is_certified' => $isCertified,
            'label' => $isCertified ? self::LABEL_CERTIFIED : null,
            'awards' => $items,
        ];
    }
}
