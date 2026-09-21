<?php

namespace App\Http\Requests\Api\V1\Admin\Staff;

use App\Http\Requests\ApiFormRequest;

class StaffStatusActionRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }
}
