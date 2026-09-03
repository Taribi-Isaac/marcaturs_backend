<?php

namespace App\Http\Requests\Api\V1\Campaigns;

use App\Enums\CampaignMarketingResourceType;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class UpdateCampaignMarketingResourceRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $type = CampaignMarketingResourceType::tryFrom((string) $this->input('type'))
            ?? $this->route('marketingResource')?->type;
        $mimes = $type instanceof CampaignMarketingResourceType
            ? $type->allowedMimes()
            : ['pdf'];

        return [
            'type' => ['sometimes', Rule::enum(CampaignMarketingResourceType::class)],
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'file' => [
                'sometimes',
                'file',
                'max:'.(int) config('campaigns.max_resource_kilobytes'),
                'mimes:'.implode(',', $mimes),
            ],
        ];
    }
}
