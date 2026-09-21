<?php

namespace App\Models;

use Database\Factories\CertificationAttemptAnswerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CertificationAttemptAnswer extends Model
{
    /** @use HasFactory<CertificationAttemptAnswerFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<CertificationAssessmentAttempt, $this>
     */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(CertificationAssessmentAttempt::class, 'attempt_id');
    }

    /**
     * @return BelongsTo<CertificationQuestion, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(CertificationQuestion::class, 'question_id');
    }

    /**
     * @return BelongsTo<CertificationQuestionOption, $this>
     */
    public function selectedOption(): BelongsTo
    {
        return $this->belongsTo(CertificationQuestionOption::class, 'selected_option_id');
    }
}
