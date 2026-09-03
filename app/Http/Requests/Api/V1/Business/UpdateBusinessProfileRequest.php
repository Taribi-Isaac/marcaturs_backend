<?php

namespace App\Http\Requests\Api\V1\Business;

use App\Http\Requests\ApiFormRequest;

class UpdateBusinessProfileRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'legal_name' => ['sometimes', 'required', 'string', 'max:255'],
            'trading_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'category' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'operating_location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'website' => ['sometimes', 'nullable', 'string', 'max:255'],
            'social_links' => ['sometimes', 'nullable', 'array'],
            'social_links.*' => ['nullable', 'string', 'max:255'],
        ];
    }
}
