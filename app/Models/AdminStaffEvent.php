<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\AdminStaffEventAction;
use App\Enums\AdminStaffRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminStaffEvent extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => AdminStaffEventAction::class,
            'previous_staff_role' => AdminStaffRole::class,
            'new_staff_role' => AdminStaffRole::class,
            'previous_status' => AccountStatus::class,
            'new_status' => AccountStatus::class,
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
