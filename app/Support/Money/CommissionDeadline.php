<?php

namespace App\Support\Money;

use App\Models\Deal;
use Carbon\CarbonInterface;

final class CommissionDeadline
{
    public static function effectiveDays(Deal $deal): int
    {
        $ceiling = max(1, (int) config('deals.commission_payment_ceiling_days', 7));
        $published = max(1, (int) $deal->commission_payment_deadline_days);

        return min($ceiling, $published);
    }

    public static function dueAt(Deal $deal): CarbonInterface
    {
        $origin = $deal->confirmed_at ?? now();

        return $origin->copy()->addDays(self::effectiveDays($deal));
    }
}
