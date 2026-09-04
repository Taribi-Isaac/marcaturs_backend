<?php

namespace App\Http\Requests\Api\V1\Disputes;

use App\Http\Requests\ApiFormRequest;

class StoreDisputeAttachmentRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKb = (int) config('disputes.max_attachment_kilobytes');
        $mimes = implode(',', config('disputes.allowed_attachment_mimes'));

        return [
            'file' => ['required', 'file', 'max:'.$maxKb, 'mimes:'.$mimes],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'disk' => ['prohibited'],
            'path' => ['prohibited'],
            'uploader_user_id' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }
}
