<?php

namespace App\Http\Requests\Api\V1\Ambassador;

use App\Http\Requests\ApiFormRequest;

class UpdateAmbassadorProfileRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'display_name' => ['sometimes', 'required', 'string', 'max:255'],
            'profile_description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'skills' => ['sometimes', 'nullable', 'array', 'max:50'],
            'skills.*' => ['string', 'max:100'],
            'marketing_interests' => ['sometimes', 'nullable', 'array', 'max:50'],
            'marketing_interests.*' => ['string', 'max:100'],
            'experience' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
