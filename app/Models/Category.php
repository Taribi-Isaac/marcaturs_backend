<?php

namespace App\Models;

use App\Enums\CategoryListingStatus;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'slug',
    'description',
    'listing_status',
    'is_active',
    'sort_order',
])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'listing_status' => CategoryListingStatus::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAssignable(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where('listing_status', '!=', CategoryListingStatus::Prohibited->value);
    }

    public function isAssignableToCampaigns(): bool
    {
        return $this->is_active && $this->listing_status->mayBeAssignedToCampaigns();
    }

    /**
     * @return HasMany<Campaign, $this>
     */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }
}
