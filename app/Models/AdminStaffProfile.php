<?php

namespace App\Models;

use App\Enums\AdminStaffRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'staff_role', 'created_by_user_id'])]
class AdminStaffProfile extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'staff_role' => AdminStaffRole::class,
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
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return HasMany<AdminStaffEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(AdminStaffEvent::class, 'target_user_id', 'user_id')->orderBy('id');
    }
}
