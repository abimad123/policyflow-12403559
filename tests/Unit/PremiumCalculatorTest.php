<?php

namespace Tests\Unit;

use App\Services\PremiumCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PremiumCalculatorTest extends TestCase
{
    private function input(array $changes = []): array
    {
        return array_replace([
            'date_of_birth' => '1990-01-01',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-10',
            'plan_code' => 'BASIC',
            'destination' => 'DOMESTIC',
            'sum_insured' => 100000,
        ], $changes);
    }

    #[DataProvider('pricingCases')]
    public function test_each_pricing_rule(array $changes, string $expected): void
    {
        $this->assertSame($expected, (new PremiumCalculator)->calculate($this->input($changes)));
    }

    public static function pricingCases(): array
    {
        return [
            'basic' => [[], '12.00'],
            'plus' => [['plan_code' => 'PLUS'], '20.00'],
            'premium' => [['plan_code' => 'PREMIUM'], '30.00'],
            'international' => [['destination' => 'INTERNATIONAL'], '21.60'],
            'coverage 300000' => [['sum_insured' => 300000], '15.00'],
            'coverage 500000' => [['sum_insured' => 500000], '18.00'],
            'age 0' => [['date_of_birth' => '2026-11-09'], '13.20'],
            'age 17' => [['date_of_birth' => '2008-11-11'], '13.20'],
            'age 18' => [['date_of_birth' => '2008-11-10'], '12.00'],
            'age 60' => [['date_of_birth' => '1966-11-10'], '12.00'],
            'age 61' => [['date_of_birth' => '1965-11-10'], '18.00'],
            'age 70' => [['date_of_birth' => '1956-11-10'], '18.00'],
            'age 71' => [['date_of_birth' => '1955-11-10'], '24.00'],
            'one inclusive day' => [['trip_start' => '2026-11-10', 'trip_end' => '2026-11-10'], '12.00'],
            'two inclusive days' => [['trip_end' => '2026-11-11'], '24.00'],
            'assignment example' => [[
                'date_of_birth' => '1998-04-10',
                'trip_end' => '2026-11-14',
                'plan_code' => 'PLUS',
                'destination' => 'INTERNATIONAL',
                'sum_insured' => 300000,
            ], '225.00'],
            'combined decimal arithmetic' => [[
                'date_of_birth' => '2008-11-11',
                'trip_end' => '2026-11-12',
                'destination' => 'INTERNATIONAL',
                'sum_insured' => 300000,
            ], '89.10'],
        ];
    }
}
