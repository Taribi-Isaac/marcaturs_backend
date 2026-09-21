<?php

namespace App\Http\Requests\Api\V1\Admin\Certification;

use App\Enums\CertificationResourceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCertificationResourceRequest extends FormRequest
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
        $type = CertificationResourceType::tryFrom((string) ($this->input('type') ?? ''));
        $mimes = $type?->allowedMimes() ?? ['pdf'];

        return [
            'type' => ['sometimes', Rule::enum(CertificationResourceType::class)],
            'title' => ['sometimes', 'string', 'max:255'],
            'body_text' => ['sometimes', 'nullable', 'string'],
            'external_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'file' => [
                'sometimes',
                'nullable',
                'file',
                'max:'.(int) config('certification.max_resource_kilobytes'),
                'mimes:'.implode(',', $mimes !== [] ? $mimes : ['pdf']),
            ],
        ];
    }
}
