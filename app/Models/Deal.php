<?php

namespace App\Models;

use App\Enums\CommissionTrigger;
use App\Enums\CommissionType;
use App\Enums\DealStatus;
use App\Enums\PricingMethod;
use Database\Factories\DealFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Deal extends Model
{
    /** @use HasFactory<DealFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DealStatus::class,
            'pricing_method' => PricingMethod::class,
            'price_amount' => 'decimal:2',
            'commission_type' => CommissionType::class,
            'commission_rate' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'commission_trigger' => CommissionTrigger::class,
            'commission_payment_deadline_days' => 'integer',
            'minimum_qualifying_amount' => 'decimal:2',
            'expected_transaction_amount' => 'decimal:2',
            'confirmed_payment_amount' => 'decimal:2',
            'confirmed_at' => 'datetime',
        ];
    }

    public function isParty(User $user): bool
    {
        return $this->business_user_id === $user->id || $this->ambassador_user_id === $user->id;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(User::class, 'business_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function ambassador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ambassador_user_id');
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return BelongsTo<CampaignVersion, $this>
     */
    public function campaignVersion(): BelongsTo
    {
        return $this->belongsTo(CampaignVersion::class);
    }

    /**
     * @return HasMany<DealEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(DealEvent::class)->orderBy('id');
    }

    /**
     * @return HasMany<PaymentEvidence, $this>
     */
    public function paymentEvidences(): HasMany
    {
        return $this->hasMany(PaymentEvidence::class)->orderBy('id');
    }

    /**
     * @return HasOne<Commission, $this>
     */
    public function commission(): HasOne
    {
        return $this->hasOne(Commission::class);
    }
}
