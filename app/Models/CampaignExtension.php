<?php

namespace App\Models;

use App\Enums\CampaignStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignExtension extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration_days' => 'integer',
            'amount_minor' => 'integer',
            'previous_listing_expires_at' => 'datetime',
            'resulting_listing_expires_at' => 'datetime',
            'previous_status' => CampaignStatus::class,
            'resulting_status' => CampaignStatus::class,
            'applied_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<CampaignExtensionPackage, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(CampaignExtensionPackage::class, 'campaign_extension_package_id');
    }

    /**
     * @return BelongsTo<PlatformPayment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(PlatformPayment::class, 'platform_payment_id');
    }
}
