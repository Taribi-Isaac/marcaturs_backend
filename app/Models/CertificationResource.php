<?php

namespace App\Models;

use App\Enums\CertificationResourceType;
use Database\Factories\CertificationResourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CertificationResource extends Model
{
    /** @use HasFactory<CertificationResourceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CertificationResourceType::class,
            'sort_order' => 'integer',
            'size_bytes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<CertificationLesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(CertificationLesson::class, 'lesson_id');
    }

    public function hasPrivateFile(): bool
    {
        return $this->type === CertificationResourceType::Downloadable
            && filled($this->disk)
            && filled($this->path);
    }
}
