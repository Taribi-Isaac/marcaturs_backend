<?php

namespace App\Models;

use App\Enums\DisputeStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dispute extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DisputeStatus::class,
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<Dispute>  $query
     * @return Builder<Dispute>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', DisputeStatus::openValues());
    }

    public function isParty(User $user): bool
    {
        return $this->reporter_user_id === $user->id || $this->accused_user_id === $user->id;
    }

    /**
     * @return BelongsTo<Deal, $this>
     */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /**
     * @return BelongsTo<Commission, $this>
     */
    public function commission(): BelongsTo
    {
        return $this->belongsTo(Commission::class);
    }

    /**
     * @return BelongsTo<DisputeCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(DisputeCategory::class, 'category_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function accused(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accused_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolvedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_admin_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_admin_user_id');
    }

    /**
     * @return HasMany<DisputeEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(DisputeEvent::class)->orderBy('id');
    }

    /**
     * @return HasMany<DisputeAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(DisputeAttachment::class)->orderBy('id');
    }
}
