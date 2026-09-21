<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CertificationQuestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CertificationQuestion
 */
class CertificationQuestionResource extends JsonResource
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
            'assessment_id' => $this->assessment_id,
            'prompt' => $this->prompt,
            'type' => $this->type->value,
            'sort_order' => $this->sort_order,
            'options' => $this->when(
                $this->relationLoaded('options'),
                fn () => $this->options->map(
                    fn ($option) => (new CertificationQuestionOptionResource($option, $this->admin))->resolve($request),
                )->values()->all(),
            ),
            'created_at' => $this->when($this->admin, $this->created_at?->toIso8601String()),
            'updated_at' => $this->when($this->admin, $this->updated_at?->toIso8601String()),
        ];
    }
}
