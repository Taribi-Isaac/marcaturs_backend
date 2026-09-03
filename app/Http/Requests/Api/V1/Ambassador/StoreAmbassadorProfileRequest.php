<?php

namespace App\Http\Requests\Api\V1\Ambassador;

use App\Http\Requests\ApiFormRequest;

class StoreAmbassadorProfileRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'max:255'],
            'profile_description' => ['nullable', 'string', 'max:5000'],
            'location' => ['nullable', 'string', 'max:255'],
            'skills' => ['nullable', 'array', 'max:50'],
            'skills.*' => ['string', 'max:100'],
            'marketing_interests' => ['nullable', 'array', 'max:50'],
            'marketing_interests.*' => ['string', 'max:100'],
            'experience' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
