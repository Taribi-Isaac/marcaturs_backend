<?php

namespace App\Models;

use App\Enums\CertificationCertificateArtifactStatus;
use App\Enums\CertificationCertificateStatus;
use Database\Factories\CertificationCertificateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CertificationCertificate extends Model
{
    /** @use HasFactory<CertificationCertificateFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CertificationCertificateStatus::class,
            'artifact_status' => CertificationCertificateArtifactStatus::class,
            'issued_at' => 'datetime',
            'artifact_generated_at' => 'datetime',
            'artifact_failed_at' => 'datetime',
            'programme_version_number' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<CertificationAward, $this>
     */
    public function award(): BelongsTo
    {
        return $this->belongsTo(CertificationAward::class, 'award_id');
    }

    public function hasGeneratedArtifact(): bool
    {
        return $this->artifact_status === CertificationCertificateArtifactStatus::Generated
            && filled($this->artifact_disk)
            && filled($this->artifact_path);
    }
}
