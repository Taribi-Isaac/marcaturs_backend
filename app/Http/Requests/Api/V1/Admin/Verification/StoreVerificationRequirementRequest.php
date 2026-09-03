<?php

namespace App\Http\Requests\Api\V1\Admin\Verification;

use App\Enums\Role;
use App\Enums\VerificationRequirementType;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class StoreVerificationRequirementRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'participant_type' => ['required', Rule::in([Role::Business->value, Role::Ambassador->value])],
            'requirement_type' => ['required', Rule::enum(VerificationRequirementType::class)],
            'is_required' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'config' => ['nullable', 'array'],
        ];
    }
}
