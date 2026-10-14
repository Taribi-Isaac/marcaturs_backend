<?php

namespace App\Http\Requests\Api\V1\Admin\RegistrationApprovals;

use App\Http\Requests\ApiFormRequest;

class RevokeRegistrationApprovalRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
