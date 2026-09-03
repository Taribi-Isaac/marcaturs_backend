<?php

namespace App\Http\Requests\Api\V1\Admin\Verification;

use App\Enums\Role;
use App\Enums\VerificationRequirementType;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class UpdateVerificationRequirementRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'participant_type' => ['sometimes', Rule::in([Role::Business->value, Role::Ambassador->value])],
            'requirement_type' => ['sometimes', Rule::enum(VerificationRequirementType::class)],
            'is_required' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'config' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
