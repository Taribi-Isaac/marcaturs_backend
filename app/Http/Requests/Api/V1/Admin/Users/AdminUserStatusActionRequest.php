<?php

namespace App\Http\Requests\Api\V1\Admin\Users;

use App\Http\Requests\ApiFormRequest;

class AdminUserStatusActionRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:5000'],
        ];
    }
}
