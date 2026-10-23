<?php

namespace App\Services;

use DateTimeImmutable;
use InvalidArgumentException;

class PremiumCalculator
{
    private const DAILY_RATES = ['BASIC' => 12, 'PLUS' => 20, 'PREMIUM' => 30];

    private const DESTINATION_MULTIPLIERS = ['DOMESTIC' => 100, 'INTERNATIONAL' => 180];

    private const COVERAGE_MULTIPLIERS = [100000 => 100, 300000 => 125, 500000 => 150];

    /**
     * Return a two-decimal rupee string without floating-point arithmetic.
     * Inputs have already passed the intake request validator.
     */
    public function calculate(array $input): string
    {
        $birth = new DateTimeImmutable($input['date_of_birth']);
        $start = new DateTimeImmutable($input['trip_start']);
        $end = new DateTimeImmutable($input['trip_end']);

        $age = $birth->diff($start)->y;
        $days = max(1, (int) $start->diff($end)->days + 1);

        $ageMultiplier = match (true) {
            $age <= 17 => 110,
            $age <= 60 => 100,
            $age <= 70 => 150,
            default => 200,
        };

        $rate = self::DAILY_RATES[$input['plan_code']] ?? throw new InvalidArgumentException('Unsupported plan.');
        $destinationMultiplier = self::DESTINATION_MULTIPLIERS[$input['destination']]
            ?? throw new InvalidArgumentException('Unsupported destination.');
        $coverageMultiplier = self::COVERAGE_MULTIPLIERS[$input['sum_insured']]
            ?? throw new InvalidArgumentException('Unsupported sum insured.');

        // Rate * 100 converts rupees to paise. Each multiplier is scaled by 100.
        $scaledPaise = $rate * 100 * $days * $ageMultiplier * $destinationMultiplier * $coverageMultiplier;
        $paise = intdiv($scaledPaise + 500000, 1000000);

        return sprintf('%d.%02d', intdiv($paise, 100), $paise % 100);
    }
}
