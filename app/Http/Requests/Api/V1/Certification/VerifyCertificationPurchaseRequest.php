<?php

namespace App\Http\Requests\Api\V1\Certification;

use Illuminate\Foundation\Http\FormRequest;

class VerifyCertificationPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'max:64'],
        ];
    }
}
