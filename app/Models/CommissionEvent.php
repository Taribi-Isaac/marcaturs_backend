<?php

namespace App\Models;

use App\Enums\CommissionEventType;
use App\Enums\CommissionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionEvent extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CommissionEventType::class,
            'previous_status' => CommissionStatus::class,
            'new_status' => CommissionStatus::class,
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Commission, $this>
     */
    public function commission(): BelongsTo
    {
        return $this->belongsTo(Commission::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
