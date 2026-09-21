<?php

namespace App\Http\Requests\Api\V1\Admin\Staff;

use App\Enums\AdminStaffRole;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class ChangeStaffRoleRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'staff_role' => ['required', Rule::in(AdminStaffRole::values())],
        ];
    }
}
