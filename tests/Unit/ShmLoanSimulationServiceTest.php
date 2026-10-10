<?php

namespace Tests\Unit;

use App\Models\ShmLoan;
use App\Services\Interest\InterestCalculator;
use App\Services\Shm\ShmLoanSimulationService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Ticket 64 — unit test simulasi pinjaman SHM.
 *
 * Test ini sengaja TIDAK mengunci nilai hasil perhitungan sebuah formula:
 * formula & periodisasi SHM masih PENDING BUSINESS CONFIRMATION. Yang diuji
 * hanyalah kontrak yang sudah pasti:
 * - rate resmi SHM = 2%;
 * - tabel angsuran memiliki struktur No/Pokok/Jasa/Total/Saldo dengan jumlah
 *   baris = tenor;
 * - pokok terbayar seluruhnya dan saldo akhir 0 (konservasi, bukan formula);
 * - metode/periode tidak dikenal ditolak, bukan ditebak;
 * - biaya dihitung dari input manual, blokir dana tidak dipotong.
 */
class ShmLoanSimulationServiceTest extends TestCase
{
    private ShmLoanSimulationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ShmLoanSimulationService(new InterestCalculator);
    }

    public static function combinationProvider(): array
    {
        $cases = [];

        foreach (['flat', 'effective', 'annuity'] as $method) {
            foreach (['yearly', 'monthly'] as $period) {
                $cases["$method/$period"] = [$method, $period];
            }
        }

        return $cases;
    }

    public function test_official_rate_is_two_percent(): void
    {
        $this->assertSame(2.0, ShmLoanSimulationService::officialRate());
        $this->assertSame(2.0, (float) ShmLoan::OFFICIAL_RATE_PERCENT);
    }

    public function test_default_rate_is_used_when_input_omits_rate(): void
    {
        $result = $this->service->simulate([
            'principal' => 12_000_000,
            'tenor_months' => 6,
            'method' => 'flat',
            'rate_period' => 'yearly',
        ]);

        $this->assertSame(2.0, $result['rate_percent']);
    }

    #[DataProvider('combinationProvider')]
    public function test_schedule_structure_is_correct_for_every_supported_input(string $method, string $period): void
    {
        $principal = 24_000_000.0;
        $tenor = 6;

        $result = $this->service->simulate([
            'principal' => $principal,
            'tenor_months' => $tenor,
            'rate_percent' => 2,
            'method' => $method,
            'rate_period' => $period,
        ]);

        // Jumlah baris = tenor, urut dari periode 1.
        $this->assertCount($tenor, $result['rows']);
        $this->assertSame([1, 2, 3, 4, 5, 6], array_column($result['rows'], 'period'));

        // Struktur kolom: No, Pokok, Jasa/Bunga, Total, Saldo.
        foreach ($result['rows'] as $row) {
            $this->assertSame(
                ['period', 'principal', 'interest', 'total', 'balance'],
                array_keys($row),
            );
        }

        // Konservasi: seluruh pokok terbayar dan saldo akhir 0.
        $this->assertEqualsWithDelta(
            $principal,
            array_sum(array_column($result['rows'], 'principal')),
            0.01,
        );
        $this->assertSame(0.0, (float) end($result['rows'])['balance']);

        // Jasa terakumulasi sama dengan total jasa yang dilaporkan.
        $this->assertEqualsWithDelta(
            $result['total_interest'],
            array_sum(array_column($result['rows'], 'interest')),
            0.01,
        );

        // Total kewajiban = pokok + jasa.
        $this->assertEqualsWithDelta(
            $principal + $result['total_interest'],
            $result['total_obligation'],
            0.01,
        );

        // Total per baris = pokok + jasa baris tersebut.
        foreach ($result['rows'] as $row) {
            $this->assertEqualsWithDelta(
                $row['principal'] + $row['interest'],
                $row['total'],
                0.01,
            );
        }

        $this->assertSame($method, $result['method']);
        $this->assertSame($period, $result['rate_period']);
        $this->assertSame(2.0, $result['rate_percent']);
    }

    public function test_unknown_method_is_rejected_not_guessed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->simulate([
            'principal' => 1_000_000,
            'tenor_months' => 12,
            'method' => 'anuitas-sembilan-persen',
            'rate_period' => 'yearly',
        ]);
    }

    public function test_unknown_rate_period_is_rejected_not_guessed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->simulate([
            'principal' => 1_000_000,
            'tenor_months' => 12,
            'method' => 'flat',
            'rate_period' => 'weekly',
        ]);
    }

    public function test_fees_are_summed_from_manual_input_and_block_funds_are_not_deducted(): void
    {
        $result = $this->service->simulate([
            'principal' => 10_000_000,
            'tenor_months' => 10,
            'method' => 'flat',
            'rate_period' => 'yearly',
            'fees' => [
                'administration' => 100_000,
                'provision' => 50_000,
                'insurance' => 25_000,
                'other' => 5_000,
                'block_funds' => 500_000,
            ],
        ]);

        $this->assertSame(180_000.0, $result['total_fees']);
        $this->assertSame(500_000.0, $result['block_funds']);
        // Blokir dana bukan biaya: tidak dipotong dari pencairan.
        $this->assertSame(9_820_000.0, $result['net_disbursement']);
    }

    public function test_missing_fees_default_to_zero(): void
    {
        $result = $this->service->simulate([
            'principal' => 1_000_000,
            'tenor_months' => 12,
            'method' => 'flat',
            'rate_period' => 'monthly',
        ]);

        $this->assertSame(0.0, $result['total_fees']);
        $this->assertSame(0.0, $result['block_funds']);
        $this->assertSame(1_000_000.0, $result['net_disbursement']);
    }
}
