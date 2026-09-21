<?php

namespace App\Models;

use Database\Factories\CertificationQuestionOptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CertificationQuestionOption extends Model
{
    /** @use HasFactory<CertificationQuestionOptionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<CertificationQuestion, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(CertificationQuestion::class, 'question_id');
    }
}
