<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignVersionStatus;
use App\Enums\CommissionTrigger;
use App\Enums\CommissionType;
use App\Enums\PricingMethod;
use App\Models\Campaign;
use App\Models\CampaignVersion;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CampaignVersionService
{
    /**
     * @return Collection<int, CampaignVersion>
     */
    public function index(User $user, Campaign $campaign): Collection
    {
        $this->assertOwner($user, $campaign);

        return $campaign->versions()->orderBy('version_number')->get();
    }

    public function show(User $user, Campaign $campaign, CampaignVersion $version): CampaignVersion
    {
        $this->assertOwner($user, $campaign);
        $this->assertBelongsToCampaign($campaign, $version);

        return $version;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $user, Campaign $campaign, array $attributes): CampaignVersion
    {
        $this->assertOwner($user, $campaign);
        $this->assertCampaignAllowsVersionMutation($campaign);

        try {
            return DB::transaction(function () use ($campaign, $attributes) {
                $locked = Campaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();

                if ($locked->versions()->where('status', CampaignVersionStatus::Draft->value)->exists()) {
                    throw new HttpResponseException(ApiResponse::error(
                        ApiErrorCode::CONFLICT,
                        'A draft version already exists. Update or publish it before creating another version.',
                        409,
                    ));
                }

                $next = (int) $locked->versions()->max('version_number') + 1;

                $version = new CampaignVersion;
                $version->campaign_id = $locked->id;
                $version->version_number = $next;
                $version->status = CampaignVersionStatus::Draft;
                $version->fill($this->termAttributes($attributes));
                $version->product_name = (string) ($attributes['product_name'] ?? $locked->title);
                $version->price_currency = strtoupper((string) ($attributes['price_currency'] ?? 'NGN'));
                $version->save();

                return $version->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::CONFLICT,
                'A campaign version with this number already exists.',
                409,
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $user, Campaign $campaign, CampaignVersion $version, array $attributes): CampaignVersion
    {
        $this->assertOwner($user, $campaign);
        $this->assertBelongsToCampaign($campaign, $version);
        $this->assertCampaignAllowsVersionMutation($campaign);
        $this->assertMutable($version);

        $version->fill($this->termAttributes($attributes));

        if (array_key_exists('price_currency', $attributes) && $attributes['price_currency'] !== null) {
            $version->price_currency = strtoupper((string) $attributes['price_currency']);
        }

        $version->save();

        return $version->refresh();
    }

    public function publish(User $user, Campaign $campaign, CampaignVersion $version): CampaignVersion
    {
        $this->assertOwner($user, $campaign);
        $this->assertBelongsToCampaign($campaign, $version);
        $this->assertCampaignAllowsVersionMutation($campaign);
        $this->assertMutable($version);
        $this->assertPublishable($version);

        return DB::transaction(function () use ($campaign, $version) {
            $lockedVersion = CampaignVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
            $this->assertMutable($lockedVersion);

            $lockedVersion->status = CampaignVersionStatus::Published;
            $lockedVersion->published_at = now();
            $lockedVersion->save();

            $campaign->current_campaign_version_id = $lockedVersion->id;
            $campaign->save();

            return $lockedVersion->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function termAttributes(array $attributes): array
    {
        return collect($attributes)->only([
            'product_name',
            'product_description',
            'pricing_method',
            'price_amount',
            'service_area',
            'commission_type',
            'commission_rate',
            'commission_amount',
            'commission_trigger',
            'commission_trigger_description',
            'commission_payment_deadline_days',
            'minimum_qualifying_amount',
            'qualifying_conditions',
            'refund_cancellation_rules',
            'approved_claims',
            'prohibited_claims',
            'brand_use_rules',
            'geographic_customer_restrictions',
            'approved_copy',
            'marketing_links',
            'payment_destination_name',
            'payment_provider',
            'payment_account_identifier',
            'payment_instructions',
            'payment_contact',
            'terms',
        ])->all();
    }

    private function assertCampaignAllowsVersionMutation(Campaign $campaign): void
    {
        if (! $campaign->status->allowsVersionMutation()) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::CONFLICT,
                'Campaign versions cannot be changed in the current campaign lifecycle state.',
                409,
            ));
        }
    }

    private function assertOwner(User $user, Campaign $campaign): void
    {
        if (! $user->isBusiness() || $campaign->user_id !== $user->id) {
            throw new AuthorizationException('You are not authorized to perform this action.');
        }
    }

    private function assertBelongsToCampaign(Campaign $campaign, CampaignVersion $version): void
    {
        if ($version->campaign_id !== $campaign->id) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::NOT_FOUND,
                'The requested resource was not found.',
                404,
            ));
        }
    }

    private function assertMutable(CampaignVersion $version): void
    {
        if (! $version->status->isMutable()) {
            throw new HttpResponseException(ApiResponse::error(
                ApiErrorCode::CONFLICT,
                'Published campaign versions are immutable.',
                409,
            ));
        }
    }

    private function assertPublishable(CampaignVersion $version): void
    {
        $payload = [
            'product_name' => $version->product_name,
            'pricing_method' => $version->pricing_method?->value,
            'price_amount' => $version->price_amount,
            'commission_type' => $version->commission_type?->value,
            'commission_rate' => $version->commission_rate,
            'commission_amount' => $version->commission_amount,
            'commission_trigger' => $version->commission_trigger?->value,
            'commission_trigger_description' => $version->commission_trigger_description,
            'commission_payment_deadline_days' => $version->commission_payment_deadline_days,
            'refund_cancellation_rules' => $version->refund_cancellation_rules,
            'payment_destination_name' => $version->payment_destination_name,
            'payment_provider' => $version->payment_provider,
            'payment_account_identifier' => $version->payment_account_identifier,
            'payment_instructions' => $version->payment_instructions,
        ];

        $rules = [
            'product_name' => ['required', 'string', 'max:255'],
            'pricing_method' => ['required', 'string'],
            'commission_type' => ['required', 'string'],
            'commission_trigger' => ['required', 'string'],
            'commission_payment_deadline_days' => ['required', 'integer', 'min:1', 'max:365'],
            'refund_cancellation_rules' => ['required', 'string'],
            'payment_destination_name' => ['required', 'string', 'max:255'],
            'payment_provider' => ['required', 'string', 'max:255'],
            'payment_account_identifier' => ['required', 'string', 'max:255'],
            'payment_instructions' => ['required', 'string'],
        ];

        if ($version->pricing_method === PricingMethod::Fixed) {
            $rules['price_amount'] = ['required', 'numeric', 'min:0'];
        }

        if ($version->commission_type === CommissionType::Percentage) {
            $rules['commission_rate'] = ['required', 'numeric', 'min:0', 'max:100'];
        }

        if ($version->commission_type === CommissionType::Fixed) {
            $rules['commission_amount'] = ['required', 'numeric', 'min:0'];
        }

        if ($version->commission_trigger === CommissionTrigger::Other) {
            $rules['commission_trigger_description'] = ['required', 'string', 'max:255'];
        }

        $validator = validator($payload, $rules);

        if ($validator->fails()) {
            throw new HttpResponseException(ApiResponse::validation($validator));
        }
    }
}
