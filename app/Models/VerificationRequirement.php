<?php

namespace App\Models;

use App\Enums\Role;
use App\Enums\VerificationRequirementType;
use Database\Factories\VerificationRequirementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'description',
    'participant_type',
    'requirement_type',
    'is_required',
    'is_active',
    'sort_order',
    'config',
])]
class VerificationRequirement extends Model
{
    /** @use HasFactory<VerificationRequirementFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'participant_type' => Role::class,
            'requirement_type' => VerificationRequirementType::class,
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'config' => 'array',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForParticipant(Builder $query, Role $role): Builder
    {
        return $query->where('participant_type', $role->value);
    }

    /**
     * @return HasMany<VerificationSubmission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(VerificationSubmission::class);
    }
}
