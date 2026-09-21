<?php

namespace App\Models;

use App\Enums\CertificationLessonContentType;
use Database\Factories\CertificationLessonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CertificationLesson extends Model
{
    /** @use HasFactory<CertificationLessonFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'content_type' => CertificationLessonContentType::class,
            'is_required' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<CertificationModule, $this>
     */
    public function module(): BelongsTo
    {
        return $this->belongsTo(CertificationModule::class, 'module_id');
    }

    /**
     * @return HasMany<CertificationResource, $this>
     */
    public function resources(): HasMany
    {
        return $this->hasMany(CertificationResource::class, 'lesson_id')->orderBy('sort_order');
    }
}
