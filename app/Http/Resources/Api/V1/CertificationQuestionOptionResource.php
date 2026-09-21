<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CertificationQuestionOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CertificationQuestionOption
 */
class CertificationQuestionOptionResource extends JsonResource
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
            'question_id' => $this->question_id,
            'label' => $this->label,
            'sort_order' => $this->sort_order,
            'is_correct' => $this->when($this->admin, $this->is_correct),
        ];
    }
}
