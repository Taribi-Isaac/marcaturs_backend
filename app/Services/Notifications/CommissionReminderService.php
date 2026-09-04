<?php

namespace App\Services\Notifications;

use App\Enums\CommissionStatus;
use App\Enums\NotificationType;
use App\Models\Commission;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Commission Reminder Engine (MH-BE-022E).
 *
 * Derives Business payment-pressure reminder slots deterministically from
 * `due_at`. Never mutates Commission or Deal financial state.
 *
 * Five Business slots (TOTAL cap):
 *  1. commission_pre_deadline      @ due_at - 2 days
 *  2. commission_deadline          @ due_at
 *  3. commission_overdue           @ due_at + 1 day
 *  4. commission_overdue_follow_up @ due_at + 4 days
 *  5. commission_overdue_follow_up @ due_at + 7 days
 */
class CommissionReminderService
{
    /**
     * @var list<array{type: NotificationType, offset_days: int}>
     */
    private const BUSINESS_SLOTS = [
        ['type' => NotificationType::CommissionPreDeadline, 'offset_days' => -2],
        ['type' => NotificationType::CommissionDeadline, 'offset_days' => 0],
        ['type' => NotificationType::CommissionOverdue, 'offset_days' => 1],
        ['type' => NotificationType::CommissionOverdueFollowUp, 'offset_days' => 4],
        ['type' => NotificationType::CommissionOverdueFollowUp, 'offset_days' => 7],
    ];

    public function __construct(
        private readonly CommissionNotificationDispatcher $dispatcher,
    ) {}

    /**
     * @return array{candidates: int, dispatched: int, skipped: int, failures: int}
     */
    public function processReminders(?Carbon $now = null): array
    {
        $now = $now ?? now();

        $candidates = Commission::query()
            ->where('status', CommissionStatus::Due)
            ->whereNotNull('due_at')
            ->where('due_at', '<=', $now->copy()->addDays(2))
            ->pluck('id')
            ->all();

        $dispatched = 0;
        $skipped = 0;
        $failures = 0;

        foreach ($candidates as $commissionId) {
            try {
                $result = $this->processOne((int) $commissionId, $now);
                $dispatched += $result['dispatched'];
                $skipped += $result['skipped'];
            } catch (\Throwable $exception) {
                $failures++;
                Log::warning('Commission reminder processing failed', [
                    'commission_id' => $commissionId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return [
            'candidates' => count($candidates),
            'dispatched' => $dispatched,
            'skipped' => $skipped,
            'failures' => $failures,
        ];
    }

    /**
     * @return array{dispatched: int, skipped: int}
     */
    private function processOne(int $commissionId, Carbon $now): array
    {
        $commission = Commission::query()
            ->with(['business', 'ambassador'])
            ->find($commissionId);

        if ($commission === null || ! $commission->status->isDue() || $commission->due_at === null) {
            return ['dispatched' => 0, 'skipped' => 1];
        }

        $dispatched = 0;
        $skipped = 0;

        foreach (self::BUSINESS_SLOTS as $slot) {
            $threshold = $commission->due_at->copy()->addDays($slot['offset_days']);

            if ($now->lt($threshold)) {
                $skipped++;

                continue;
            }

            // Re-check before each dispatch (commission may have been paid mid-loop).
            $commission->refresh();
            if (! $commission->status->isDue()) {
                $skipped++;

                continue;
            }

            $scheduledDate = $threshold->toDateString();

            $this->dispatcher->sendToUser(
                $commission->business_user_id,
                $commission,
                $slot['type'],
                $scheduledDate,
            );
            $dispatched++;
        }

        // Ambassador overdue awareness: single notification when overdue, not the 5-slot cadence.
        $commission->refresh();
        if ($commission->status->isDue() && $commission->due_at !== null && $now->greaterThan($commission->due_at)) {
            $this->dispatcher->notifyAmbassadorOverdueAwareness($commission);
            $dispatched++;
        }

        return ['dispatched' => $dispatched, 'skipped' => $skipped];
    }

    /**
     * @return list<array{type: NotificationType, offset_days: int, threshold: Carbon, scheduled_date: string}>
     */
    public function slotsFor(Commission $commission): array
    {
        if ($commission->due_at === null) {
            return [];
        }

        $slots = [];
        foreach (self::BUSINESS_SLOTS as $slot) {
            $threshold = $commission->due_at->copy()->addDays($slot['offset_days']);
            $slots[] = [
                'type' => $slot['type'],
                'offset_days' => $slot['offset_days'],
                'threshold' => $threshold,
                'scheduled_date' => $threshold->toDateString(),
            ];
        }

        return $slots;
    }
}
