<?php

namespace App\Models;

use App\Enums\CertificationEnrollmentStatus;
use Database\Factories\CertificationEnrollmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CertificationEnrollment extends Model
{
    /** @use HasFactory<CertificationEnrollmentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CertificationEnrollmentStatus::class,
            'fee_amount_minor' => 'integer',
            'enrolled_at' => 'datetime',
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
     * @return BelongsTo<CertificationProgramme, $this>
     */
    public function programme(): BelongsTo
    {
        return $this->belongsTo(CertificationProgramme::class, 'programme_id');
    }

    /**
     * @return BelongsTo<CertificationProgrammeVersion, $this>
     */
    public function programmeVersion(): BelongsTo
    {
        return $this->belongsTo(CertificationProgrammeVersion::class, 'programme_version_id');
    }

    /**
     * @return BelongsTo<PlatformPayment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(PlatformPayment::class, 'platform_payment_id');
    }

    /**
     * @return HasMany<CertificationLessonProgress, $this>
     */
    public function lessonProgress(): HasMany
    {
        return $this->hasMany(CertificationLessonProgress::class, 'enrollment_id');
    }

    /**
     * @return HasMany<CertificationAssessmentAttempt, $this>
     */
    public function assessmentAttempts(): HasMany
    {
        return $this->hasMany(CertificationAssessmentAttempt::class, 'enrollment_id');
    }

    /**
     * @return HasOne<CertificationAward, $this>
     */
    public function award(): HasOne
    {
        return $this->hasOne(CertificationAward::class, 'enrollment_id');
    }
}
