<?php

namespace App\Models;

use App\Enums\VerificationSubmissionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VerificationSubmissionVersion extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => VerificationSubmissionStatus::class,
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<VerificationSubmission, $this>
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(VerificationSubmission::class, 'verification_submission_id');
    }

    /**
     * @return HasMany<VerificationEvidence, $this>
     */
    public function evidence(): HasMany
    {
        return $this->hasMany(VerificationEvidence::class);
    }
}
