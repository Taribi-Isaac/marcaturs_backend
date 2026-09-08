<?php

namespace App\Http\Requests\Api\V1\Admin\Disputes;

use App\Enums\DisputeStatus;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class AdminDisputeIndexRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(DisputeStatus::class)],
        ];
    }
}
