<?php

namespace App\Console\Commands;

use App\Services\Campaigns\CampaignFeaturedService;
use App\Services\Campaigns\CampaignLifecycleService;
use Illuminate\Console\Command;

class ProcessCampaignLifecycleCommand extends Command
{
    protected $signature = 'campaigns:process-lifecycle';

    protected $description = 'Move due campaigns to expiring or expired without deleting them';

    public function handle(
        CampaignLifecycleService $lifecycle,
        CampaignFeaturedService $featured,
    ): int {
        $result = $lifecycle->processDueCampaigns();
        $cleared = $featured->clearExpiredFeaturedFlags();

        $this->info("Marked {$result['expiring']} campaign(s) expiring and {$result['expired']} expired.");
        $this->info("Cleared {$cleared} expired Featured flag(s).");

        return self::SUCCESS;
    }
}
