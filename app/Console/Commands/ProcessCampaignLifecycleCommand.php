<?php

namespace App\Console\Commands;

use App\Services\Campaigns\CampaignLifecycleService;
use Illuminate\Console\Command;

class ProcessCampaignLifecycleCommand extends Command
{
    protected $signature = 'campaigns:process-lifecycle';

    protected $description = 'Move due campaigns to expiring or expired without deleting them';

    public function handle(CampaignLifecycleService $lifecycle): int
    {
        $result = $lifecycle->processDueCampaigns();

        $this->info("Marked {$result['expiring']} campaign(s) expiring and {$result['expired']} expired.");

        return self::SUCCESS;
    }
}
