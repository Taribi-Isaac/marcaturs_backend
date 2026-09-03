<?php

namespace App\Models;

use App\Enums\VerificationSubmissionStatus;
use Database\Factories\VerificationSubmissionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable([
    'status',
    'text_value',
    'current_version',
    'review_reason',
    'reviewer_notes',
    'submitted_at',
    'reviewed_at',
])]
#[Hidden(['reviewer_notes'])]
class VerificationSubmission extends Model
{
    /** @use HasFactory<VerificationSubmissionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => VerificationSubmissionStatus::class,
            'current_version' => 'integer',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
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
     * @return BelongsTo<VerificationRequirement, $this>
     */
    public function requirement(): BelongsTo
    {
        return $this->belongsTo(VerificationRequirement::class, 'verification_requirement_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return HasMany<VerificationSubmissionVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(VerificationSubmissionVersion::class);
    }

    /**
     * @return HasMany<VerificationEvidence, $this>
     */
    public function evidence(): HasMany
    {
        return $this->hasMany(VerificationEvidence::class);
    }

    /**
     * @return HasMany<VerificationReviewEvent, $this>
     */
    public function reviewEvents(): HasMany
    {
        return $this->hasMany(VerificationReviewEvent::class);
    }

    /**
     * @return Collection<int, VerificationEvidence>
     */
    public function evidenceForCurrentVersion(): Collection
    {
        $this->loadMissing(['evidence', 'versions']);

        $versionId = $this->versions->firstWhere('version', $this->current_version)?->id;

        if ($versionId === null) {
            return collect();
        }

        return $this->evidence
            ->where('verification_submission_version_id', $versionId)
            ->values();
    }
}
