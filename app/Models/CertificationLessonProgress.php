<?php

namespace App\Models;

use App\Enums\CertificationLessonProgressStatus;
use Database\Factories\CertificationLessonProgressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CertificationLessonProgress extends Model
{
    /** @use HasFactory<CertificationLessonProgressFactory> */
    use HasFactory;

    protected $table = 'certification_lesson_progress';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CertificationLessonProgressStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CertificationEnrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(CertificationEnrollment::class, 'enrollment_id');
    }

    /**
     * @return BelongsTo<CertificationLesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(CertificationLesson::class, 'lesson_id');
    }
}
