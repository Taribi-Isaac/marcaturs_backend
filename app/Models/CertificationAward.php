<?php

namespace App\Models;

use App\Enums\CertificationAwardStatus;
use Database\Factories\CertificationAwardFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CertificationAward extends Model
{
    /** @use HasFactory<CertificationAwardFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CertificationAwardStatus::class,
            'awarded_at' => 'datetime',
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
     * @return BelongsTo<CertificationEnrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(CertificationEnrollment::class, 'enrollment_id');
    }

    /**
     * @return BelongsTo<CertificationAssessmentAttempt, $this>
     */
    public function assessmentAttempt(): BelongsTo
    {
        return $this->belongsTo(CertificationAssessmentAttempt::class, 'assessment_attempt_id');
    }

    /**
     * @return HasOne<CertificationCertificate, $this>
     */
    public function certificate(): HasOne
    {
        return $this->hasOne(CertificationCertificate::class, 'award_id');
    }
}
