<?php

namespace Tests\Unit;

use App\Services\PremiumCalculatorService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PremiumCalculatorServiceTest extends TestCase
{
    protected PremiumCalculatorService $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new PremiumCalculatorService();
    }

    /**
     * Test the exact calculation example given in Section 4 of the challenge PDF:
     * - Plan: PLUS (base = 20)
     * - Destination: INTERNATIONAL (1.80)
     * - Sum Insured: 300,000 (1.25)
     * - Trip: 2026-11-10 to 2026-11-14 (5 days)
     * - DOB: 1998-04-10 (Age 28 on trip start => 1.00)
     * - Premium = 20 * 5 * 1.00 * 1.80 * 1.25 = 225.00
     */
    public function test_assignment_specification_example_premium(): void
    {
        $result = $this->calculator->calculate(
            planCode: 'PLUS',
            sumInsured: 300000,
            destination: 'INTERNATIONAL',
            dateOfBirth: '1998-04-10',
            tripStart: '2026-11-10',
            tripEnd: '2026-11-14'
        );

        $this->assertEquals(5, $result['travel_days']);
        $this->assertEquals('1.00', $result['age_multiplier']);
        $this->assertEquals('1.80', $result['destination_multiplier']);
        $this->assertEquals('1.25', $result['coverage_multiplier']);
        $this->assertEquals('20.00', $result['base_daily_rate']);
        $this->assertEquals('225.00', $result['premium']);
    }

    /**
     * Test age brackets:
     * 0–17: 1.10; 18–60: 1.00; 61–70: 1.50; 71+: 2.00
     */
    public function test_age_multipliers_across_all_brackets(): void
    {
        // Child (10 yo)
        $this->assertEquals('1.10', $this->calculator->getAgeMultiplier(10));
        $this->assertEquals('1.10', $this->calculator->getAgeMultiplier(17));

        // Adult (35 yo, 60 yo)
        $this->assertEquals('1.00', $this->calculator->getAgeMultiplier(18));
        $this->assertEquals('1.00', $this->calculator->getAgeMultiplier(60));

        // Senior (65 yo, 70 yo)
        $this->assertEquals('1.50', $this->calculator->getAgeMultiplier(61));
        $this->assertEquals('1.50', $this->calculator->getAgeMultiplier(70));

        // Super senior (75 yo)
        $this->assertEquals('2.00', $this->calculator->getAgeMultiplier(71));
        $this->assertEquals('2.00', $this->calculator->getAgeMultiplier(85));
    }

    /**
     * Test domestic destination multiplier (1.00).
     */
    public function test_domestic_destination_pricing(): void
    {
        $result = $this->calculator->calculate(
            planCode: 'BASIC', // 12
            sumInsured: 100000, // 1.00
            destination: 'DOMESTIC', // 1.00
            dateOfBirth: '1990-01-01', // age > 18 => 1.00
            tripStart: '2026-06-01',
            tripEnd: '2026-06-03' // 3 days
        );

        // 12 * 3 * 1.00 * 1.00 * 1.00 = 36.00
        $this->assertEquals('36.00', $result['premium']);
    }

    /**
     * Test premium plan and maximum coverage multiplier.
     */
    public function test_premium_plan_with_high_coverage(): void
    {
        $result = $this->calculator->calculate(
            planCode: 'PREMIUM', // 30
            sumInsured: 500000, // 1.50
            destination: 'INTERNATIONAL', // 1.80
            dateOfBirth: '1955-01-01', // Age ~71 => 2.00
            tripStart: '2026-07-01',
            tripEnd: '2026-07-10' // 10 days
        );

        // 30 * 10 * 2.00 * 1.80 * 1.50 = 1620.00
        $this->assertEquals('1620.00', $result['premium']);
    }

    /**
     * Reject unsupported plan code.
     */
    public function test_rejects_unsupported_plan_code(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->calculate(
            planCode: 'TITANIUM',
            sumInsured: 100000,
            destination: 'DOMESTIC',
            dateOfBirth: '1990-01-01',
            tripStart: '2026-06-01',
            tripEnd: '2026-06-02'
        );
    }

    /**
     * Reject unsupported coverage amount.
     */
    public function test_rejects_unsupported_coverage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->calculate(
            planCode: 'BASIC',
            sumInsured: 250000,
            destination: 'DOMESTIC',
            dateOfBirth: '1990-01-01',
            tripStart: '2026-06-01',
            tripEnd: '2026-06-02'
        );
    }
}
