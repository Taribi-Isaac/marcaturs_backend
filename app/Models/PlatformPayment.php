<?php

namespace App\Models;

use App\Enums\PlatformPaymentPurpose;
use App\Enums\PlatformPaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PlatformPayment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => PlatformPaymentPurpose::class,
            'status' => PlatformPaymentStatus::class,
            'amount_minor' => 'integer',
            'duration_days' => 'integer',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return BelongsTo<CampaignExtensionPackage, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(CampaignExtensionPackage::class, 'campaign_extension_package_id');
    }

    /**
     * @return HasOne<CampaignExtension, $this>
     */
    public function campaignExtension(): HasOne
    {
        return $this->hasOne(CampaignExtension::class);
    }
}
