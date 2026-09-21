<?php

namespace App\Models;

use App\Enums\CertificationAdminEventAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CertificationAdminEvent extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => CertificationAdminEventAction::class,
            'payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * @return BelongsTo<CertificationProgramme, $this>
     */
    public function programme(): BelongsTo
    {
        return $this->belongsTo(CertificationProgramme::class, 'programme_id');
    }

    /**
     * @return BelongsTo<CertificationProgrammeVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(CertificationProgrammeVersion::class, 'programme_version_id');
    }
}
