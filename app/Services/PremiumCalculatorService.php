<?php

namespace App\Services;

use Carbon\Carbon;
use InvalidArgumentException;

class PremiumCalculatorService
{
    public const BASE_RATES = [
        'BASIC' => '12.00',
        'PLUS' => '20.00',
        'PREMIUM' => '30.00',
    ];

    public const COVERAGE_MULTIPLIERS = [
        100000 => '1.00',
        300000 => '1.25',
        500000 => '1.50',
    ];

    public const DESTINATION_MULTIPLIERS = [
        'DOMESTIC' => '1.00',
        'INTERNATIONAL' => '1.80',
    ];

    /**
     * Calculate premium and breakdown.
     *
     * @param string $planCode
     * @param int $sumInsured
     * @param string $destination
     * @param Carbon|string $dateOfBirth
     * @param Carbon|string $tripStart
     * @param Carbon|string $tripEnd
     * @return array
     */
    public function calculate(
        string $planCode,
        int $sumInsured,
        string $destination,
        $dateOfBirth,
        $tripStart,
        $tripEnd
    ): array {
        $plan = strtoupper($planCode);
        if (!isset(self::BASE_RATES[$plan])) {
            throw new InvalidArgumentException("Unsupported plan code: {$planCode}");
        }
        $baseRate = self::BASE_RATES[$plan];

        if (!isset(self::COVERAGE_MULTIPLIERS[$sumInsured])) {
            throw new InvalidArgumentException("Unsupported sum insured / coverage amount: {$sumInsured}");
        }
        $coverageMultiplier = self::COVERAGE_MULTIPLIERS[$sumInsured];

        $dest = strtoupper($destination);
        if (!isset(self::DESTINATION_MULTIPLIERS[$dest])) {
            throw new InvalidArgumentException("Unsupported destination: {$destination}");
        }
        $destinationMultiplier = self::DESTINATION_MULTIPLIERS[$dest];

        $dob = $dateOfBirth instanceof Carbon ? $dateOfBirth : Carbon::parse($dateOfBirth);
        $start = $tripStart instanceof Carbon ? $tripStart : Carbon::parse($tripStart);
        $end = $tripEnd instanceof Carbon ? $tripEnd : Carbon::parse($tripEnd);

        if ($end->lt($start)) {
            throw new InvalidArgumentException("Trip end date must be on or after trip start date.");
        }

        // Inclusive travel days: trip_end - trip_start + 1
        $daysDiff = $start->diffInDays($end);
        $travelDays = max(1, (int)$daysDiff + 1);

        // Age calculated on trip_start date
        $ageOnTripStart = (int) $dob->diffInYears($start);
        $ageMultiplier = $this->getAgeMultiplier($ageOnTripStart);

        // Decimal-safe calculation using bcmath
        // formula: premium = base_daily_rate * travel_days * age_multiplier * destination_multiplier * coverage_multiplier
        $step1 = bcmul($baseRate, (string) $travelDays, 4);
        $step2 = bcmul($step1, $ageMultiplier, 4);
        $step3 = bcmul($step2, $destinationMultiplier, 4);
        $rawPremium = bcmul($step3, $coverageMultiplier, 4);

        // Round to 2 decimal places using half-up arithmetic
        $premium = number_format((float) $rawPremium, 2, '.', '');

        return [
            'premium' => $premium,
            'travel_days' => $travelDays,
            'age_on_trip_start' => $ageOnTripStart,
            'age_multiplier' => $ageMultiplier,
            'destination_multiplier' => $destinationMultiplier,
            'coverage_multiplier' => $coverageMultiplier,
            'base_daily_rate' => $baseRate,
        ];
    }

    /**
     * Age multiplier based on rules:
     * 0-17: 1.10
     * 18-60: 1.00
     * 61-70: 1.50
     * 71+: 2.00
     */
    public function getAgeMultiplier(int $age): string
    {
        if ($age < 0) {
            throw new InvalidArgumentException("Age cannot be negative: {$age}");
        }

        if ($age <= 17) {
            return '1.10';
        }

        if ($age <= 60) {
            return '1.00';
        }

        if ($age <= 70) {
            return '1.50';
        }

        return '2.00';
    }
}
