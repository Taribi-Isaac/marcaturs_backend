<?php

namespace Tests\Unit;

use App\Support\Money\DecimalMoney;
use PHPUnit\Framework\TestCase;

class DecimalMoneyTest extends TestCase
{
    public function test_percentage_of_uses_bcmath_scale_not_floats(): void
    {
        $this->assertSame('10000.00', DecimalMoney::percentageOf('100000.00', '10.00'));
        $this->assertSame('10500.05', DecimalMoney::percentageOf('100000.50', '10.50'));
        $this->assertSame('0.01', DecimalMoney::percentageOf('0.10', '10.00'));
    }
}
