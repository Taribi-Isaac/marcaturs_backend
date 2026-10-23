<?php

namespace App\Support\Money;

use App\Models\Deal;
use Carbon\CarbonInterface;

final class CommissionDeadline
{
    /**
     * Platform maximum (SoT §7.2). Campaign Version validation uses the same value, so a
     * published deadline can be stricter than this but never looser.
     */
    public static function ceilingDays(): int
    {
        return max(1, (int) config('deals.commission_payment_ceiling_days', 7));
    }

    public static function effectiveDays(Deal $deal): int
    {
        $published = max(1, (int) $deal->commission_payment_deadline_days);

        return min(self::ceilingDays(), $published);
    }

    public static function dueAt(Deal $deal): CarbonInterface
    {
        $origin = $deal->confirmed_at ?? now();

        return $origin->copy()->addDays(self::effectiveDays($deal));
    }
}
