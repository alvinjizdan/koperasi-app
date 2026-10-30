<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\ShmCollectionCase;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanSettlement;
use App\Models\ShmSettlementCorrection;
use App\Models\User;
use App\Services\Accounting\FinancialReportService;
use App\Support\AccountingContext;
use Database\Seeders\Demo\DemoDatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoDatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeder_runs_deterministically_and_creates_coherent_dataset(): void
    {
        // 1. Jalankan Seeder
        $this->seed(DemoDatabaseSeeder::class);

        // 2. Verifikasi Akun Pengguna Demo
        $this->assertDatabaseHas('users', ['email' => 'superadmin@ksp.local']);
        $this->assertDatabaseHas('users', ['email' => 'admin.demo@ksp.local']);
        $this->assertDatabaseHas('users', ['email' => 'marketing.demo@ksp.local']);
        $this->assertDatabaseHas('users', ['email' => 'direktur.demo@ksp.local']);
        $this->assertDatabaseHas('users', ['email' => 'bm.demo@ksp.local']);

        // 3. Verifikasi Anggota & Nasabah SHM
        $this->assertGreaterThanOrEqual(10, Member::count());
        $this->assertGreaterThanOrEqual(10, ShmCustomer::count());

        // Verifikasi Customer Number memiliki format independen NNNNN-ANG
        $customer = ShmCustomer::first();
        $this->assertNotNull($customer->member_id);
        $this->assertMatchesRegularExpression('/^[0-9]{5}-ANG$/', $customer->customer_number);

        // 4. Verifikasi Pengajuan Pinjaman & Pinjaman Aktif
        $this->assertGreaterThanOrEqual(8, ShmLoanApplication::count());
        $this->assertGreaterThanOrEqual(5, ShmLoan::count());

        // 5. Verifikasi Pelunasan & Koreksi Pelunasan
        $this->assertGreaterThanOrEqual(1, ShmLoanSettlement::where('status', ShmLoanSettlement::STATUS_COMPLETED)->count());
        $this->assertGreaterThanOrEqual(1, ShmSettlementCorrection::count());

        // 6. Verifikasi Collection
        $this->assertGreaterThanOrEqual(1, ShmCollectionCase::where('status', ShmCollectionCase::STATUS_PROMISE_TO_PAY)->count());
        $this->assertGreaterThanOrEqual(1, ShmCollectionCase::where('status', ShmCollectionCase::STATUS_ESCALATED)->count());

        // 7. Verifikasi Akuntansi & Laporan Finansial
        $reportService = app(FinancialReportService::class);
        $asOfDate = now()->toDateString();

        $tb = $reportService->trialBalance(null, $asOfDate, false, AccountingContext::SHM);
        $this->assertTrue($tb['balanced']);
        $this->assertSame($tb['totals']['debit'], $tb['totals']['credit']);

        $bs = $reportService->balanceSheet($asOfDate, AccountingContext::SHM);
        $this->assertTrue($bs['summary']['balanced_with_result']);
        $this->assertSame(
            $bs['summary']['total_assets'],
            round($bs['summary']['total_liabilities'] + $bs['summary']['total_equity'] + $bs['summary']['current_period_result'], 2)
        );

        // 8. Re-run Seeder untuk menguji idempotensi
        $this->seed(DemoDatabaseSeeder::class);

        // Pastikan jumlah pengguna demo tidak bertambah duplikat
        $this->assertSame(5, User::whereIn('email', [
            'superadmin@ksp.local',
            'admin.demo@ksp.local',
            'marketing.demo@ksp.local',
            'direktur.demo@ksp.local',
            'bm.demo@ksp.local',
        ])->count());
    }
}
