<?php

namespace App\Models;

use Database\Factories\CampaignBookmarkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'ambassador_user_id',
    'campaign_id',
])]
class CampaignBookmark extends Model
{
    /** @use HasFactory<CampaignBookmarkFactory> */
    use HasFactory;

    /**
     * Set for the current request when listing bookmarks. Not a database column.
     */
    public bool $currentlyDiscoverable = false;

    /**
     * @return BelongsTo<User, $this>
     */
    public function ambassador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ambassador_user_id');
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
