<?php

namespace Tests\Unit;

use App\DTO\PricingDecision;
use App\Services\ExactCostCalculator;
use PHPUnit\Framework\TestCase;

class ExactCostCalculatorTest extends TestCase
{
    public function test_exact_pricing_rounds_up_submicrounit_cost_and_prices_cached_input(): void
    {
        $pricing = new PricingDecision(
            '01M1G000000000000000000001',
            new \DateTimeImmutable,
            'CAD',
            '3.50000000',
            '10.50000000',
            '1.75000000',
        );
        $calculator = new ExactCostCalculator;

        $this->assertSame(49, $calculator->actualMicrounits($pricing, 10, 2, 4));
        $this->assertSame(1, $calculator->actualMicrounits(
            new PricingDecision(
                '01M1G000000000000000000002',
                new \DateTimeImmutable,
                'CAD',
                '0.00000001',
                '0',
                null,
            ),
            1,
            0,
            0,
        ));
        $this->assertSame(1234567, $calculator->dollarsToMicrounits('1.234567'));
    }
}
