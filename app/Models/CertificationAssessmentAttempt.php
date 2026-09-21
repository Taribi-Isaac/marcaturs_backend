<?php

namespace App\Models;

use App\Enums\CertificationAssessmentAttemptStatus;
use Database\Factories\CertificationAssessmentAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CertificationAssessmentAttempt extends Model
{
    /** @use HasFactory<CertificationAssessmentAttemptFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CertificationAssessmentAttemptStatus::class,
            'attempt_number' => 'integer',
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'correct_count' => 'integer',
            'total_questions' => 'integer',
            'score_percent' => 'decimal:2',
            'passed' => 'boolean',
            'pass_mark_percent' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<CertificationEnrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(CertificationEnrollment::class, 'enrollment_id');
    }

    /**
     * @return BelongsTo<CertificationAssessment, $this>
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(CertificationAssessment::class, 'assessment_id');
    }

    /**
     * @return BelongsTo<CertificationProgrammeVersion, $this>
     */
    public function programmeVersion(): BelongsTo
    {
        return $this->belongsTo(CertificationProgrammeVersion::class, 'programme_version_id');
    }

    /**
     * @return HasMany<CertificationAttemptAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(CertificationAttemptAnswer::class, 'attempt_id');
    }

    /**
     * @return HasOne<CertificationAward, $this>
     */
    public function award(): HasOne
    {
        return $this->hasOne(CertificationAward::class, 'assessment_attempt_id');
    }
}
