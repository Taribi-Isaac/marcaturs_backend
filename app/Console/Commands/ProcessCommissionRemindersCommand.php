<?php

namespace App\Console\Commands;

use App\Services\Notifications\CommissionReminderService;
use Illuminate\Console\Command;

class ProcessCommissionRemindersCommand extends Command
{
    protected $signature = 'commissions:process-reminders';

    protected $description = 'Generate eligible Commission payment reminders and awareness notifications';

    public function handle(CommissionReminderService $service): int
    {
        $result = $service->processReminders();

        $this->info(
            "Reminders: {$result['candidates']} candidate(s), {$result['dispatched']} dispatched, "
            ."{$result['skipped']} skipped, {$result['failures']} failure(s).",
        );

        return self::SUCCESS;
    }
}
