<?php

namespace App\Models;

use App\Enums\CertificationProgrammeStatus;
use Database\Factories\CertificationProgrammeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CertificationProgramme extends Model
{
    /** @use HasFactory<CertificationProgrammeFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CertificationProgrammeStatus::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<CertificationProgrammeVersion, $this>
     */
    public function currentPublishedVersion(): BelongsTo
    {
        return $this->belongsTo(CertificationProgrammeVersion::class, 'current_published_version_id');
    }

    /**
     * @return HasMany<CertificationProgrammeVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(CertificationProgrammeVersion::class, 'programme_id');
    }

    /**
     * @return HasMany<CertificationAdminEvent, $this>
     */
    public function adminEvents(): HasMany
    {
        return $this->hasMany(CertificationAdminEvent::class, 'programme_id');
    }
}
