<?php

namespace App\Models;

use App\Enums\CommissionStatus;
use App\Enums\CommissionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Commission extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CommissionStatus::class,
            'commission_type' => CommissionType::class,
            'commission_rate' => 'decimal:2',
            'amount' => 'decimal:2',
            'became_due_at' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function isOverdue(): bool
    {
        return $this->status->isDue() && $this->due_at !== null && now()->greaterThan($this->due_at);
    }

    public function isParty(User $user): bool
    {
        return $this->business_user_id === $user->id || $this->ambassador_user_id === $user->id;
    }

    /**
     * @return BelongsTo<Deal, $this>
     */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
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
     * @return BelongsTo<CampaignVersion, $this>
     */
    public function campaignVersion(): BelongsTo
    {
        return $this->belongsTo(CampaignVersion::class);
    }

    /**
     * @return HasMany<CommissionEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(CommissionEvent::class)->orderBy('id');
    }
}
