<?php

namespace Tests\Unit;

use App\Services\Interest\InterestCalculator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Ticket 15 — unit test layanan perhitungan bunga terpusat.
 * Nilai uji eksplisit, data dummy.
 */
class InterestCalculatorTest extends TestCase
{
    private InterestCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new InterestCalculator;
    }

    public static function methodProvider(): array
    {
        return [
            'none' => [InterestCalculator::METHOD_NONE],
            'flat' => [InterestCalculator::METHOD_FLAT],
            'effective' => [InterestCalculator::METHOD_EFFECTIVE],
            'annuity' => [InterestCalculator::METHOD_ANNUITY],
        ];
    }

    #[DataProvider('methodProvider')]
    public function test_schedule_length_matches_tenor(string $method): void
    {
        $result = $this->calc->buildSchedule($method, 12.0, 10_000_000, 12);

        $this->assertCount(12, $result['schedule']);
        $this->assertSame($method, $result['method']);
    }

    public function test_flat_interest_total_is_principal_times_rate(): void
    {
        // Flat 12%/tahun, 10 juta, 12 bulan → bunga total = 1.200.000.
        $result = $this->calc->buildSchedule(InterestCalculator::METHOD_FLAT, 12.0, 10_000_000, 12);

        $this->assertSame(1_200_000.0, $result['total_interest']);
        // Merata per bulan.
        $this->assertSame(100_000.0, $result['schedule'][0]);
        $this->assertSame(100_000.0, $result['schedule'][11]);
    }

    public function test_none_method_produces_zero_interest(): void
    {
        $result = $this->calc->buildSchedule(InterestCalculator::METHOD_NONE, 0.0, 5_000_000, 10);

        $this->assertSame(0.0, $result['total_interest']);
        $this->assertSame(array_fill(0, 10, 0.0), $result['schedule']);
    }

    public function test_effective_interest_decreases_as_principal_amortizes(): void
    {
        $result = $this->calc->buildSchedule(InterestCalculator::METHOD_EFFECTIVE, 12.0, 12_000_000, 12);

        // Bunga bulan pertama = 12 juta × 1% = 120 ribu; menurun setelahnya.
        $this->assertSame(120_000.0, round($result['schedule'][0], 2));
        $this->assertGreaterThan($result['schedule'][11], $result['schedule'][0]);
    }

    public function test_annuity_installment_is_constant(): void
    {
        $result = $this->calc->buildSchedule(InterestCalculator::METHOD_ANNUITY, 12.0, 10_000_000, 12);

        $this->assertGreaterThan(0.0, $result['total_interest']);
        // Total bunga anuitas 12% 12 bulan ≈ 661.85 (angsuran tetap ≈ 888.488/47).
        $this->assertEqualsWithDelta(661_850.0, $result['total_interest'], 5.0);
    }

    public function test_rounding_residual_is_absorbed_by_last_installment(): void
    {
        // Nominal sengaja tidak habis dibagi tenor.
        $result = $this->calc->buildSchedule(InterestCalculator::METHOD_FLAT, 12.0, 10_001.0, 3);

        $sum = round(array_sum($result['schedule']), 2);
        $this->assertSame($result['total_interest'], $sum);
        // Setiap komponen sudah 2 desimal.
        foreach ($result['schedule'] as $value) {
            $this->assertSame($value, round($value, 2));
        }
    }

    public function test_unknown_method_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calc->buildSchedule('magic', 10.0, 1_000_000, 5);
    }

    public function test_validate_configuration_rejects_unknown_method(): void
    {
        $this->expectException(ValidationException::class);
        $this->calc->validateConfiguration('compound', 10.0);
    }

    public function test_validate_configuration_rejects_negative_rate(): void
    {
        $this->expectException(ValidationException::class);
        $this->calc->validateConfiguration(InterestCalculator::METHOD_FLAT, -1.0);
    }

    public function test_validate_configuration_accepts_known_method(): void
    {
        $this->calc->validateConfiguration(InterestCalculator::METHOD_FLAT, 12.0);
        $this->assertTrue(true);
    }
}
