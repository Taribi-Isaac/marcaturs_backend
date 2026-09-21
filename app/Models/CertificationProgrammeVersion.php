<?php

namespace App\Models;

use App\Enums\CertificationProgrammeVersionStatus;
use Database\Factories\CertificationProgrammeVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CertificationProgrammeVersion extends Model
{
    /** @use HasFactory<CertificationProgrammeVersionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CertificationProgrammeVersionStatus::class,
            'version_number' => 'integer',
            'fee_amount_minor' => 'integer',
            'pass_mark_percent' => 'decimal:2',
            'published_at' => 'datetime',
            'unpublished_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'version_number';
    }

    /**
     * @return BelongsTo<CertificationProgramme, $this>
     */
    public function programme(): BelongsTo
    {
        return $this->belongsTo(CertificationProgramme::class, 'programme_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return HasMany<CertificationAdminEvent, $this>
     */
    public function adminEvents(): HasMany
    {
        return $this->hasMany(CertificationAdminEvent::class, 'programme_version_id');
    }

    /**
     * @return HasMany<CertificationModule, $this>
     */
    public function modules(): HasMany
    {
        return $this->hasMany(CertificationModule::class, 'programme_version_id')->orderBy('sort_order');
    }

    /**
     * @return HasOne<CertificationAssessment, $this>
     */
    public function assessment(): HasOne
    {
        return $this->hasOne(CertificationAssessment::class, 'programme_version_id');
    }

    /**
     * Scoped route binding for assessment questions nested under a version.
     *
     * @param  string|null  $field
     */
    public function resolveChildRouteBinding($childType, $value, $field = null)
    {
        if ($childType === 'question') {
            return CertificationQuestion::query()
                ->whereKey($value)
                ->whereHas('assessment', function ($query): void {
                    $query->where('programme_version_id', $this->getKey());
                })
                ->firstOrFail();
        }

        return parent::resolveChildRouteBinding($childType, $value, $field);
    }
}
