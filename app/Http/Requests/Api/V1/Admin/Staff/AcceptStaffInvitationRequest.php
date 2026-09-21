<?php

namespace App\Http\Requests\Api\V1\Admin\Staff;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rules\Password;

class AcceptStaffInvitationRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'min:32', 'max:128'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ];
    }
}
