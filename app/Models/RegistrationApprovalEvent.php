<?php

namespace App\Models;

use App\Enums\RegistrationApprovalEventAction;
use App\Enums\RegistrationApprovalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegistrationApprovalEvent extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => RegistrationApprovalEventAction::class,
            'previous_status' => RegistrationApprovalStatus::class,
            'new_status' => RegistrationApprovalStatus::class,
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<RegistrationApproval, $this>
     */
    public function approval(): BelongsTo
    {
        return $this->belongsTo(RegistrationApproval::class, 'registration_approval_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
