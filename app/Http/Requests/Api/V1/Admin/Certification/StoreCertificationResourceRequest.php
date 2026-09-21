<?php

namespace App\Http\Requests\Api\V1\Admin\Certification;

use App\Enums\CertificationResourceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCertificationResourceRequest extends FormRequest
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
        $type = CertificationResourceType::tryFrom((string) $this->input('type'));
        $mimes = $type?->allowedMimes() ?? ['pdf'];

        return [
            'type' => ['required', Rule::enum(CertificationResourceType::class)],
            'title' => ['required', 'string', 'max:255'],
            'body_text' => ['sometimes', 'nullable', 'string'],
            'external_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'file' => [
                Rule::requiredIf(fn () => $type?->requiresFile() === true),
                'nullable',
                'file',
                'max:'.(int) config('certification.max_resource_kilobytes'),
                'mimes:'.implode(',', $mimes),
            ],
        ];
    }
}
