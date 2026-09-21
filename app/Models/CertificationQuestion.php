<?php

namespace App\Models;

use App\Enums\CertificationQuestionType;
use Database\Factories\CertificationQuestionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CertificationQuestion extends Model
{
    /** @use HasFactory<CertificationQuestionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CertificationQuestionType::class,
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<CertificationAssessment, $this>
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(CertificationAssessment::class, 'assessment_id');
    }

    /**
     * @return HasMany<CertificationQuestionOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(CertificationQuestionOption::class, 'question_id')->orderBy('sort_order');
    }
}
