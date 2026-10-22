<?php

namespace App\Http\Requests\Api\V1\Marketplace;

use App\Http\Requests\ApiFormRequest;

class CampaignBookmarkIndexRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('api.pagination.max_per_page')],
        ];
    }
}
