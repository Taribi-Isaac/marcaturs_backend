<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CertificationEnrollment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CertificationEnrollment
 */
class CertificationEnrollmentResource extends JsonResource
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
            'status' => $this->status->value,
            'programme_id' => $this->programme_id,
            'programme_version_id' => $this->programme_version_id,
            'programme' => $this->when(
                $this->relationLoaded('programme') && $this->programme !== null,
                fn () => [
                    'id' => $this->programme->id,
                    'name' => $this->programme->name,
                    'status' => $this->programme->status->value,
                ],
            ),
            'programme_version' => $this->when(
                $this->relationLoaded('programmeVersion') && $this->programmeVersion !== null,
                fn () => [
                    'id' => $this->programmeVersion->id,
                    'version_number' => $this->programmeVersion->version_number,
                    'status' => $this->programmeVersion->status->value,
                ],
            ),
            'fee_amount_minor' => $this->fee_amount_minor,
            'fee_currency' => $this->fee_currency,
            'enrolled_at' => $this->enrolled_at?->toIso8601String(),
            'payment' => $this->when(
                $this->relationLoaded('payment') && $this->payment !== null,
                fn () => (new PlatformPaymentResource($this->payment))->resolve($request),
            ),
            'user' => $this->when(
                $this->admin && $this->relationLoaded('user') && $this->user !== null,
                fn () => [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                ],
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
