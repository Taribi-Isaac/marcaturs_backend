<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CertificationProgrammeVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CertificationProgrammeVersion
 */
class CertificationProgrammeVersionResource extends JsonResource
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
            'programme_id' => $this->programme_id,
            'version_number' => $this->version_number,
            'status' => $this->status->value,
            'fee_amount_minor' => $this->fee_amount_minor,
            'fee_currency' => $this->fee_currency,
            'pass_mark_percent' => $this->when(
                $this->admin,
                $this->pass_mark_percent !== null ? (string) $this->pass_mark_percent : null,
            ),
            'published_at' => $this->published_at?->toIso8601String(),
            'unpublished_at' => $this->when($this->admin, $this->unpublished_at?->toIso8601String()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->when($this->admin, $this->updated_at?->toIso8601String()),
        ];
    }
}
