<?php

namespace App\Console\Commands;

use App\Services\Deals\CommissionOverdueService;
use Illuminate\Console\Command;

class ProcessCommissionOverdueCommand extends Command
{
    protected $signature = 'commissions:process-overdue';

    protected $description = 'Detect overdue commissions and emit first-crossing audit events';

    public function handle(CommissionOverdueService $service): int
    {
        $result = $service->processOverdue();

        $this->info(
            "Overdue: {$result['candidates']} candidate(s), {$result['newly_overdue']} newly detected, "
            ."{$result['already_processed']} already processed, {$result['failures']} failure(s).",
        );

        return self::SUCCESS;
    }
}
