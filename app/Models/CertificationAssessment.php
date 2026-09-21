<?php

namespace App\Models;

use Database\Factories\CertificationAssessmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CertificationAssessment extends Model
{
    /** @use HasFactory<CertificationAssessmentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'configuration' => 'array',
        ];
    }

    /**
     * Authoritative pass mark lives on the Programme Version (MH-BE-048).
     */
    public function authoritativePassMarkPercent(): ?string
    {
        $version = $this->relationLoaded('programmeVersion')
            ? $this->programmeVersion
            : $this->programmeVersion()->first();

        if ($version === null || $version->pass_mark_percent === null) {
            return null;
        }

        return (string) $version->pass_mark_percent;
    }

    /**
     * @return BelongsTo<CertificationProgrammeVersion, $this>
     */
    public function programmeVersion(): BelongsTo
    {
        return $this->belongsTo(CertificationProgrammeVersion::class, 'programme_version_id');
    }

    /**
     * @return HasMany<CertificationQuestion, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(CertificationQuestion::class, 'assessment_id')->orderBy('sort_order');
    }

    /**
     * @return HasMany<CertificationAssessmentAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(CertificationAssessmentAttempt::class, 'assessment_id');
    }
}
