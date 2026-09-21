<?php

namespace App\Http\Requests\Api\V1\Admin\Staff;

use App\Enums\AdminStaffRole;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CreateStaffDirectRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', Password::defaults()],
            'staff_role' => ['required', Rule::in(AdminStaffRole::values())],
        ];
    }
}
