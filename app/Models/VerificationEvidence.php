<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Hidden(['disk', 'path'])]
class VerificationEvidence extends Model
{
    /**
     * @return BelongsTo<VerificationSubmission, $this>
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(VerificationSubmission::class, 'verification_submission_id');
    }

    /**
     * @return BelongsTo<VerificationSubmissionVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(VerificationSubmissionVersion::class, 'verification_submission_version_id');
    }
}
