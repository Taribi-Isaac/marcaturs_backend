<?php

namespace App\Models;

use Database\Factories\CertificationModuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CertificationModule extends Model
{
    /** @use HasFactory<CertificationModuleFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<CertificationProgrammeVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(CertificationProgrammeVersion::class, 'programme_version_id');
    }

    /**
     * @return HasMany<CertificationLesson, $this>
     */
    public function lessons(): HasMany
    {
        return $this->hasMany(CertificationLesson::class, 'module_id')->orderBy('sort_order');
    }
}
