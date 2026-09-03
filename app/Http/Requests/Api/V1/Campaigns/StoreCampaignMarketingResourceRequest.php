<?php

namespace App\Http\Requests\Api\V1\Campaigns;

use App\Enums\CampaignMarketingResourceType;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class StoreCampaignMarketingResourceRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $type = CampaignMarketingResourceType::tryFrom((string) $this->input('type'));
        $mimes = $type?->allowedMimes() ?? ['pdf'];

        return [
            'type' => ['required', Rule::enum(CampaignMarketingResourceType::class)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'file' => [
                'required',
                'file',
                'max:'.(int) config('campaigns.max_resource_kilobytes'),
                'mimes:'.implode(',', $mimes),
            ],
        ];
    }
}
