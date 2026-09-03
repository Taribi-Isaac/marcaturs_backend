<?php

namespace App\Models;

use App\Enums\CampaignVersionStatus;
use App\Enums\CommissionTrigger;
use App\Enums\CommissionType;
use App\Enums\PricingMethod;
use Database\Factories\CampaignVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_name',
    'product_description',
    'pricing_method',
    'price_amount',
    'price_currency',
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
])]
class CampaignVersion extends Model
{
    /** @use HasFactory<CampaignVersionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CampaignVersionStatus::class,
            'version_number' => 'integer',
            'pricing_method' => PricingMethod::class,
            'price_amount' => 'decimal:2',
            'commission_type' => CommissionType::class,
            'commission_rate' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'commission_trigger' => CommissionTrigger::class,
            'commission_payment_deadline_days' => 'integer',
            'minimum_qualifying_amount' => 'decimal:2',
            'marketing_links' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'version_number';
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
