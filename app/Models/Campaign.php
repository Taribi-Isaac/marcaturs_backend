<?php

namespace App\Models;

use App\Enums\CampaignStatus;
use App\Enums\CampaignVersionStatus;
use Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'title',
    'category_id',
    'status',
])]
class Campaign extends Model
{
    /** @use HasFactory<CampaignFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'is_featured' => 'boolean',
            'listing_starts_at' => 'datetime',
            'listing_expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'activated_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'expired_at' => 'datetime',
            'closed_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDiscoverable(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [CampaignStatus::Active->value, CampaignStatus::Expiring->value])
            ->whereNotNull('current_campaign_version_id')
            ->whereHas('currentVersion', function (Builder $version): void {
                $version->where('status', CampaignVersionStatus::Published->value);
            })
            ->whereHas('category', function (Builder $category): void {
                $category->assignable();
            });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return HasMany<CampaignVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(CampaignVersion::class);
    }

    /**
     * @return BelongsTo<CampaignVersion, $this>
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(CampaignVersion::class, 'current_campaign_version_id');
    }

    /**
     * @return HasMany<CampaignMarketingResource, $this>
     */
    public function marketingResources(): HasMany
    {
        return $this->hasMany(CampaignMarketingResource::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<CampaignExtension, $this>
     */
    public function extensions(): HasMany
    {
        return $this->hasMany(CampaignExtension::class);
    }

    /**
     * @return HasMany<PlatformPayment, $this>
     */
    public function platformPayments(): HasMany
    {
        return $this->hasMany(PlatformPayment::class);
    }

    /**
     * @return HasMany<Deal, $this>
     */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }
}
