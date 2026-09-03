<?php

namespace App\Services\Deals;

use App\Enums\CommissionEventType;
use App\Enums\CommissionStatus;
use App\Models\Commission;
use App\Models\CommissionEvent;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CommissionOverdueService
{
    /**
     * @return array{candidates: int, newly_overdue: int, already_processed: int, failures: int}
     */
    public function processOverdue(): array
    {
        $candidates = Commission::query()
            ->where('status', CommissionStatus::Due)
            ->where('due_at', '<', now())
            ->pluck('id')
            ->all();

        $newlyOverdue = 0;
        $alreadyProcessed = 0;
        $failures = 0;

        foreach ($candidates as $commissionId) {
            try {
                $result = $this->processOne($commissionId);

                if ($result === true) {
                    $newlyOverdue++;
                } elseif ($result === false) {
                    $alreadyProcessed++;
                }
            } catch (\Throwable $exception) {
                $failures++;
                Log::warning('Commission overdue processing failed', [
                    'commission_id' => $commissionId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return [
            'candidates' => count($candidates),
            'newly_overdue' => $newlyOverdue,
            'already_processed' => $alreadyProcessed,
            'failures' => $failures,
        ];
    }

    /**
     * @return bool|null true = newly created, false = already existed, null = skipped (no longer eligible)
     */
    private function processOne(int $commissionId): ?bool
    {
        return DB::transaction(function () use ($commissionId): ?bool {
            $locked = Commission::query()->whereKey($commissionId)->lockForUpdate()->first();

            if ($locked === null) {
                return null;
            }

            if (! $locked->status->isDue()) {
                return null;
            }

            if ($locked->due_at === null || ! now()->greaterThan($locked->due_at)) {
                return null;
            }

            $alreadyExists = CommissionEvent::query()
                ->where('commission_id', $locked->id)
                ->where('type', CommissionEventType::Overdue)
                ->exists();

            if ($alreadyExists) {
                return false;
            }

            try {
                $event = new CommissionEvent;
                $event->commission_id = $locked->id;
                $event->actor_user_id = null;
                $event->type = CommissionEventType::Overdue;
                $event->previous_status = $locked->status;
                $event->new_status = $locked->status;
                $event->metadata = [
                    'due_at' => $locked->due_at->toIso8601String(),
                    'detected_at' => now()->toIso8601String(),
                ];
                $event->save();
            } catch (UniqueConstraintViolationException|QueryException $exception) {
                if ($this->isUniqueViolation($exception)) {
                    return false;
                }

                throw $exception;
            }

            return true;
        });
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return $exception instanceof UniqueConstraintViolationException
            || $exception->getCode() === '23000'
            || str_contains(strtolower($exception->getMessage()), 'unique');
    }
}
