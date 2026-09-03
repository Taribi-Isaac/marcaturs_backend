<?php

namespace App\Support\Money;

final class DecimalMoney
{
    public static function normalize(string|int|float $value): string
    {
        if (is_int($value)) {
            return number_format($value, 2, '.', '');
        }

        if (is_float($value)) {
            return number_format($value, 2, '.', '');
        }

        $trimmed = trim($value);

        if ($trimmed === '' || ! is_numeric($trimmed)) {
            return '0.00';
        }

        return bcadd($trimmed, '0', 2);
    }

    public static function percentageOf(string $principal, string $rate): string
    {
        $product = bcmul(self::normalize($principal), self::normalize($rate), 8);
        $quotient = bcdiv($product, '100', 8);

        return self::roundHalfUp($quotient, 2);
    }

    public static function roundHalfUp(string $value, int $scale): string
    {
        $negative = str_starts_with($value, '-');
        $absolute = $negative ? substr($value, 1) : $value;
        $increment = bcpow('10', (string) (-$scale), $scale + 1);
        $half = bcdiv($increment, '2', $scale + 1);
        $rounded = bcadd($absolute, $half, $scale);

        return $negative ? '-'.$rounded : $rounded;
    }
}
