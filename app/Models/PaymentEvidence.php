<?php

namespace App\Models;

use App\Enums\PaymentEvidenceKind;
use App\Enums\PaymentEvidenceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentEvidence extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => PaymentEvidenceKind::class,
            'status' => PaymentEvidenceStatus::class,
            'amount' => 'decimal:2',
            'paid_on' => 'date',
            'submitted_at' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }

    public function hasFile(): bool
    {
        return $this->disk !== null && $this->path !== null;
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
    public function ambassador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ambassador_user_id');
    }
}
