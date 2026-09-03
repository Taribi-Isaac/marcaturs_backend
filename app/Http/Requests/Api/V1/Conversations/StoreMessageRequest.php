<?php

namespace App\Http\Requests\Api\V1\Conversations;

use App\Http\Requests\ApiFormRequest;

class StoreMessageRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        $content = $this->input('content');

        if (is_string($content)) {
            $this->merge(['content' => trim($content)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'content' => [
                'required',
                'string',
                'min:1',
                'max:'.(int) config('chat.message_max_characters'),
            ],
        ];
    }
}
