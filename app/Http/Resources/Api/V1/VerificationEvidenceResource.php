<?php

namespace App\Http\Resources\Api\V1;

use App\Models\VerificationEvidence;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VerificationEvidence
 */
class VerificationEvidenceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
