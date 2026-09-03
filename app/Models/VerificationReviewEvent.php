<?php

namespace App\Models;

use App\Enums\VerificationReviewAction;
use App\Enums\VerificationSubmissionStatus;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Hidden(['reviewer_notes'])]
class VerificationReviewEvent extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => VerificationReviewAction::class,
            'previous_status' => VerificationSubmissionStatus::class,
            'new_status' => VerificationSubmissionStatus::class,
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
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
