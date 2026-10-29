<?php

namespace Tests\Feature;

use App\Models\CashTransaction;
use App\Models\Member;
use App\Models\SavingProduct;
use App\Models\SavingsTransaction;
use App\Models\ShmApplication;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanProduct;
use App\Models\User;
use App\Services\CashierService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\Concurrency\ConcurrencyHarness;
use Tests\TestCase;

/**
 * T002C — Konkurensi pencairan SHM (AC-16 / I-01 runtime).
 *
 * Dua proses PHP PARALEL (proc_open, koneksi PostgreSQL masing-masing)
 * mencoba mencairkan pengajuan yang SAMA secara bersamaan. Kontrak yang
 * diuji:
 *
 * - tepat SATU worker sukses; worker kedua ditolak dengan business error
 *   (idempotency) — tanpa error query/tidak terduga;
 * - tepat SATU ShmLoan (constraint UNIQUE shm_loans.shm_loan_application_id);
 * - tepat SATU posting Simpanan Pokok dan SATU Simpanan Wajib
 *   (idempotency key deterministik);
 * - tepat SATU kas keluar net;
 * - tepat SATU audit pencairan;
 * - seluruh nominal konsisten dengan invariant finansial.
 *
 * Mengikuti pola ConcurrencyAndFailureTest: TANPA RefreshDatabase — suite
 * membuat database PostgreSQL terpisah per test (migrate + seed + drop)
 * sehingga worker paralel melihat data yang sudah commit.
 *
 * Suite ini hanya berjalan bila driver pdo_pgsql tersedia; tanpa pgsql
 * test dilewati secara eksplisit (bukan dianggap lulus tanpa pengujian).
 */
#[RequiresPhpExtension('pdo_pgsql')]
class ShmDisbursementConcurrencyTest extends TestCase
{
    private ConcurrencyHarness $harness;

    private User $marketing;

    private bool $concurrencyDatabaseReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->postgresAvailable()) {
            $this->markTestSkipped('PostgreSQL untuk pengujian konkurensi tidak tersedia.');
        }

        $this->harness = new ConcurrencyHarness($this);
        $this->harness->setUpDatabase();
        $this->concurrencyDatabaseReady = true;

        // Data dibuat pada koneksi pgsql pengujian konkurensi (sudah commit)
        // agar worker paralel dapat membacanya.
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->marketing = User::factory()->create(['email_verified_at' => now()]);
    }

    protected function tearDown(): void
    {
        if ($this->concurrencyDatabaseReady) {
            $this->harness->tearDownDatabase();
        }

        parent::tearDown();
    }

    /**
     * Pengajuan SHM yang sudah APPROVED lengkap dengan seluruh prasyarat
     * pencairan fail-closed (anggota sumber, produk simpanan aktif, sesi
     * kasir aktif milik pelaku dengan saldo kas cukup).
     *
     * Pelaku pencairan = pemilik pengajuan (marketing) — di lingkungan worker
     * tidak ada user autentik, sehingga guard ownership memakai marketing_id.
     */
    private function approvedApplicationForDisbursement(): ShmLoanApplication
    {
        SavingProduct::create([
            'code' => 'SP-CONC',
            'name' => 'Simpanan Pokok',
            'category' => SavingProduct::CATEGORY_PRINCIPAL,
            'description' => 'Simpanan Pokok (uji konkurensi T002C)',
            'is_active' => true,
        ]);

        SavingProduct::create([
            'code' => 'SW-CONC',
            'name' => 'Simpanan Wajib',
            'category' => SavingProduct::CATEGORY_MANDATORY,
            'description' => 'Simpanan Wajib (uji konkurensi T002C)',
            'is_active' => true,
        ]);

        $member = Member::factory()->create(['status' => 'active']);
        $customer = ShmCustomer::factory()->create(['member_id' => $member->id]);
        $product = ShmLoanProduct::factory()->create();

        // Mirror approval SHM (pola ShmCustomerMemberIntegrationTest).
        $shmApp = ShmApplication::create([
            'application_number' => 'MIRROR-CONC-001',
            'shm_customer_id' => $customer->id,
            'marketing_id' => $this->marketing->id,
            'status' => 'approved',
        ]);

        $shmApp->approvals()->create([
            'stage' => 'approval',
            'status' => 'approved',
            'decided_by' => $this->marketing->id,
            'decided_at' => now(),
        ]);

        $application = ShmLoanApplication::create([
            'application_number' => 'APP-CONC-001',
            'shm_customer_id' => $customer->id,
            'shm_application_id' => $shmApp->id,
            'shm_loan_product_id' => $product->id,
            'marketing_id' => $this->marketing->id,
            'principal' => 50_000_000,
            'approved_amount' => 50_000_000,
            'tenor_months' => 12,
            'first_due_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'application_date' => now()->toDateString(),
            'status' => 'pending',
        ]);

        // Prasyarat F-01: sesi kasir aktif milik pelaku pencairan.
        app(CashierService::class)->openSession([
            'operational_date' => now()->toDateString(),
            'opening_balance' => 5_000_000_000,
            'opening_notes' => 'Sesi uji konkurensi pencairan SHM (T002C).',
        ], $this->marketing->id);

        return $application;
    }

    /**
     * Dua pencairan PARALEL untuk pengajuan yang sama: hanya satu yang
     * boleh berhasil — tanpa loan ganda, tanpa posting simpanan ganda,
     * tanpa kas keluar ganda, tanpa audit ganda.
     */
    public function test_concurrent_disbursement_of_same_application_produces_exactly_one_financial_event(): void
    {
        $application = $this->approvedApplicationForDisbursement();

        $results = $this->harness->runParallel([
            ['task' => 'shm_disburse', 'params' => ['application_id' => $application->id, 'user_id' => $this->marketing->id]],
            ['task' => 'shm_disburse', 'params' => ['application_id' => $application->id, 'user_id' => $this->marketing->id]],
        ]);

        // Setiap worker hanya boleh SUKSES atau ditolak business error
        // (idempotency) — error query (mis. pelanggaran unique constraint
        // tanpa lock) atau error tak terduga GAGALKAN test ini.
        $results->assertNoPartialEffects();

        // Tepat SATU worker yang sukses mencairkan.
        $this->assertSame(
            1,
            $results->whereOk()->count(),
            'Tepat SATU pencairan paralel harus sukses: '.json_encode($results->first()->errors),
        );

        // 1 loan — tidak ada loan kedua dari percobaan paralel.
        $this->assertSame(1, ShmLoan::query()->count(), 'Tidak boleh ada pinjaman ganda.');
        $loan = ShmLoan::query()->sole();

        // Invariant finansial: gross 50jt, potongan 3,4jt, net 46,6jt.
        $this->assertSame('50000000.00', (string) $loan->principal);
        $this->assertEqualsWithDelta(46_600_000, (float) $loan->net_disbursement, 0.001);

        // Simpanan Pokok & Wajib: tepat SATU posting masing-masing
        // (idempotency key deterministik berbasis application_id).
        $principalKey = sprintf('shm-disbursement-principal:%d', $application->id);
        $mandatoryKey = sprintf('shm-disbursement-mandatory:%d', $application->id);

        $this->assertSame(
            1,
            SavingsTransaction::query()->where('idempotency_key', $principalKey)->count(),
            'Tepat SATU posting Simpanan Pokok.',
        );
        $this->assertSame(
            1,
            SavingsTransaction::query()->where('idempotency_key', $mandatoryKey)->count(),
            'Tepat SATU posting Simpanan Wajib.',
        );
        $this->assertEqualsWithDelta(
            100_000,
            (float) SavingsTransaction::query()->where('idempotency_key', $principalKey)->sum('amount'),
            0.001,
        );
        $this->assertEqualsWithDelta(
            300_000, // Rp25.000 × 12 tenor
            (float) SavingsTransaction::query()->where('idempotency_key', $mandatoryKey)->sum('amount'),
            0.001,
        );

        // Kas keluar NET: tepat SATU baris untuk loan ini.
        $cashOut = CashTransaction::query()
            ->where('source_type', ShmLoan::class)
            ->where('source_id', $loan->id)
            ->get();
        $this->assertCount(1, $cashOut, 'Tepat SATU kas keluar per pencairan.');
        $this->assertSame(CashTransaction::TYPE_EXPENSE, $cashOut->first()->type);
        $this->assertEqualsWithDelta(46_600_000, (float) $cashOut->first()->amount, 0.001);

        // Audit pencairan: tepat SATU baris.
        $this->assertSame(
            1,
            DB::table('audit_logs')->where('action', 'shm.loan_application.disbursed')->count(),
            'Tepat SATU audit pencairan.',
        );
    }

    private function postgresAvailable(): bool
    {
        try {
            config(['database.connections.pgsql_test_check' => [
                'driver' => 'pgsql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '5432'),
                'database' => 'postgres',
                'username' => env('DB_USERNAME', 'postgres'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => 'utf8',
            ]]);
            DB::connection('pgsql_test_check')->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
