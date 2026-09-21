<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CertificationCertificate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CertificationCertificate
 */
class CertificationCertificateResource extends JsonResource
{
    public function __construct($resource, private readonly bool $admin = false)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'award_id' => $this->award_id,
            'certificate_number' => $this->certificate_number,
            'status' => $this->status->value,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'recipient_name' => $this->recipient_name,
            'programme_name' => $this->programme_name,
            'programme_version_number' => $this->programme_version_number,
            'issuer_name' => $this->issuer_name,
            'artifact_status' => $this->artifact_status->value,
            'artifact_generated_at' => $this->artifact_generated_at?->toIso8601String(),
            'artifact_available' => $this->hasGeneratedArtifact(),
            'artifact_error_code' => $this->when(
                $this->admin,
                $this->artifact_error_code,
            ),
            'artifact_failed_at' => $this->when(
                $this->admin,
                $this->artifact_failed_at?->toIso8601String(),
            ),
            'award' => $this->when(
                $this->relationLoaded('award') && $this->award !== null,
                fn () => [
                    'id' => $this->award->id,
                    'status' => $this->award->status->value,
                    'programme_id' => $this->award->programme_id,
                    'programme_version_id' => $this->award->programme_version_id,
                    'enrollment_id' => $this->award->enrollment_id,
                    'assessment_attempt_id' => $this->award->assessment_attempt_id,
                    'awarded_at' => $this->award->awarded_at?->toIso8601String(),
                    'user' => $this->when(
                        $this->admin && $this->award->relationLoaded('user') && $this->award->user !== null,
                        fn () => [
                            'id' => $this->award->user->id,
                            'name' => $this->award->user->name,
                            'email' => $this->award->user->email,
                        ],
                    ),
                ],
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
