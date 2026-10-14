<?php

namespace App\Http\Requests\Api\V1\Admin\RegistrationApprovals;

use App\Enums\Role;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class StoreRegistrationApprovalRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', 'string', Rule::in(Role::publicRegistrationValues())],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->email)) {
            $this->merge([
                'email' => strtolower(trim($this->email)),
            ]);
        }

        if (is_string($this->role)) {
            $this->merge([
                'role' => strtoupper(trim($this->role)),
            ]);
        }
    }
}
