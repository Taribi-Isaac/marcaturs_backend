<?php

namespace App\Http\Requests\Api\V1\Admin\Deals;

use App\Enums\CommissionStatus;
use App\Enums\DealStatus;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class AdminDealIndexRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(DealStatus::class)],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'open_dispute' => ['sometimes', 'boolean'],
            'commission_overdue' => ['sometimes', 'boolean'],
            'commission_status' => ['sometimes', Rule::enum(CommissionStatus::class)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('api.pagination.max_per_page')],
        ];
    }
}
