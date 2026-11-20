<?php

namespace Tests\Feature;

use App\Models\AccountingImportBatch;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Role;
use App\Models\ShmLoanProduct;
use App\Models\ShmProductAccounting;
use App\Models\User;
use App\Services\Accounting\FinancialReportService;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\ShmJournalImportService;
use App\Services\Accounting\ShmProductAccountingService;
use App\Services\Accounting\SimpleXlsxService;
use App\Support\AccountingContext;
use Database\Seeders\ShmChartOfAccountSeeder;
use Database\Seeders\ShmLoanProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * TICKET 38 — SHM Settlement Discount Gross Revenue Accounting Feature Tests.
 */
class ShmSettlementDiscountGrossRevenueAccountingTest extends TestCase
{
    use RefreshDatabase;

    private User $accountingUser;

    private User $staffUser;

    private ChartOfAccount $cash;

    private ChartOfAccount $receivable;

    private ChartOfAccount $grossInterest;

    private ChartOfAccount $penalty;

    private ChartOfAccount $discount;

    private ShmLoanProduct $product;

    private FinancialReportService $reportService;

    private JournalService $journalService;

    private ShmJournalImportService $importService;

    private ShmProductAccountingService $productAccountingService;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Jalankan seeder resmi SHM CoA (T38) dan Matriks Produk SHM (T82)
        $this->seed(ShmChartOfAccountSeeder::class);
        $this->seed(ShmLoanProductSeeder::class);

        $this->reportService = app(FinancialReportService::class);
        $this->journalService = app(JournalService::class);
        $this->importService = app(ShmJournalImportService::class);
        $this->productAccountingService = app(ShmProductAccountingService::class);

        // 2. Fetch akun resmi SHM
        $this->cash = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '1-1100')->firstOrFail();
        $this->receivable = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '1-1300')->firstOrFail();
        $this->grossInterest = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '4-1101')->firstOrFail();
        $this->penalty = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '4-1103')->firstOrFail();
        $this->discount = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '4-1901')->firstOrFail();

        $this->product = ShmLoanProduct::firstOrFail();

        // 3. Setup pengguna dengan role & permission akuntansi SHM
        $this->accountingUser = User::factory()->create();
        $accountingRole = Role::firstOrCreate(
            ['name' => 'manajer_akuntansi_makro', 'entity' => 'makro'],
            ['display_name' => 'Manajer Akuntansi Makro', 'is_system' => false, 'is_active' => true]
        );
        $this->accountingUser->roles()->attach($accountingRole->id);

        $this->staffUser = User::factory()->create();
    }

    /**
     * Test A: Verifikasi registrasi CoA 4-1901 dan sifat idempotensinya.
     */
    public function test_shm_coa_41901_is_registered_idempotently_with_correct_properties(): void
    {
        $account = ChartOfAccount::query()
            ->where('accounting_context', AccountingContext::SHM)
            ->where('code', '4-1901')
            ->first();

        $this->assertNotNull($account);
        $this->assertSame('Diskon Pelunasan SHM', $account->name);
        $this->assertSame(ChartOfAccount::CATEGORY_REVENUE, $account->category);
        $this->assertSame(ChartOfAccount::NORMAL_DEBIT, $account->normal_balance);
        $this->assertTrue($account->is_active);

        // Eksekusi seeder ulang untuk memastikan idempotensi (tidak ada duplikasi atau crash)
        $this->seed(ShmChartOfAccountSeeder::class);

        $count = ChartOfAccount::query()
            ->where('accounting_context', AccountingContext::SHM)
            ->where('code', '4-1901')
            ->count();

        $this->assertSame(1, $count);
    }

    /**
     * Test B: ShmProductAccounting mengaitkan 4-1901 dan menolak akun Koperasi / non-revenue.
     */
    public function test_shm_product_accounting_accepts_41901_and_rejects_koperasi_account(): void
    {
        // Hubungkan akun ke konfigurasi produk
        $config = $this->productAccountingService->saveConfiguration([
            'shm_loan_product_id' => $this->product->id,
            'cash_account_id' => $this->cash->id,
            'receivable_account_id' => $this->receivable->id,
            'interest_revenue_account_id' => $this->grossInterest->id,
            'penalty_revenue_account_id' => $this->penalty->id,
            'settlement_discount_account_id' => $this->discount->id,
            'is_active' => true,
        ], $this->accountingUser->id);

        $this->assertSame($this->discount->id, $config->settlement_discount_account_id);

        // Buat akun Koperasi
        $koperasiAccount = ChartOfAccount::create([
            'accounting_context' => AccountingContext::KOPERASI,
            'code' => '4-1901',
            'name' => 'Diskon Koperasi',
            'category' => ChartOfAccount::CATEGORY_REVENUE,
            'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
            'is_active' => true,
        ]);

        // Coba pasang akun Koperasi -> harus gagal validasi
        $this->expectException(ValidationException::class);
        $this->productAccountingService->saveConfiguration([
            'shm_loan_product_id' => $this->product->id,
            'settlement_discount_account_id' => $koperasiAccount->id,
        ], $this->accountingUser->id);
    }

    /**
     * Test C: Jurnal manual Gross Revenue untuk Skenario 1 (Tanpa diskon) & Skenario 2 (Eligible diskon).
     */
    public function test_gross_revenue_journal_mechanics_scenario_1_and_2(): void
    {
        // --- Skenario 1: Normal Settlement (Tanpa diskon) ---
        // Pokok 10jt, Bunga 500rb, Kas 10,5jt
        $journal1 = $this->journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => now()->toDateString(),
            'description' => 'Pelunasan Normal Tanpa Diskon',
        ], [
            ['account_id' => $this->cash->id, 'debit' => 10_500_000, 'credit' => 0, 'description' => 'Kas'],
            ['account_id' => $this->receivable->id, 'debit' => 0, 'credit' => 10_000_000, 'description' => 'Pokok'],
            ['account_id' => $this->grossInterest->id, 'debit' => 0, 'credit' => 500_000, 'description' => 'Bunga'],
        ], $this->accountingUser->id);

        $this->assertSame(JournalEntry::STATUS_DRAFT, $journal1->status);
        $posted1 = $this->journalService->post($journal1, $this->accountingUser->id);
        $this->assertSame(JournalEntry::STATUS_POSTED, $posted1->status);

        // --- Skenario 2: Eligible Settlement dengan Diskon Gross ---
        // Pokok 60jt, Bunga Bruto 3jt, Diskon 500rb, Kas 62,5jt
        $journal2 = $this->journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => now()->toDateString(),
            'description' => 'Pelunasan dengan Diskon Bruto',
        ], [
            ['account_id' => $this->cash->id, 'debit' => 62_500_000, 'credit' => 0, 'description' => 'Kas Masuk'],
            ['account_id' => $this->discount->id, 'debit' => 500_000, 'credit' => 0, 'description' => 'Diskon Pelunasan (Contra)'],
            ['account_id' => $this->receivable->id, 'debit' => 0, 'credit' => 60_000_000, 'description' => 'Piutang Pokok'],
            ['account_id' => $this->grossInterest->id, 'debit' => 0, 'credit' => 3_000_000, 'description' => 'Pendapatan Bunga Bruto'],
        ], $this->accountingUser->id);

        $posted2 = $this->journalService->post($journal2, $this->accountingUser->id);
        $this->assertSame(JournalEntry::STATUS_POSTED, $posted2->status);

        // Cek Laporan Laba Rugi SHM
        $incomeReport = $this->reportService->incomeStatement(now()->toDateString(), now()->toDateString(), AccountingContext::SHM);

        $revenueSection = $incomeReport['sections'][ChartOfAccount::CATEGORY_REVENUE];
        $accounts = collect($revenueSection['accounts']);

        $grossInterestRow = $accounts->firstWhere('code', '4-1101');
        $discountRow = $accounts->firstWhere('code', '4-1901');

        $this->assertNotNull($grossInterestRow);
        $this->assertNotNull($discountRow);

        // Total Bunga Bruto = 500rb + 3jt = 3,5jt
        $this->assertEquals(3_500_000.0, $grossInterestRow['balance']);
        // Diskon Pelunasan = -500rb (karena debit balance di kelompok revenue)
        $this->assertEquals(-500_000.0, $discountRow['balance']);

        // Total Revenue Bersih = 3,5jt - 500rb = 3.000.000
        $this->assertEquals(3_000_000.0, $revenueSection['total']);
        $this->assertEquals(3_000_000.0, $incomeReport['summary']['net_profit_loss']);
    }

    /**
     * Test D: Skenario 3 (Diskon + Denda) & Skenario 6 (DRAFT vs POSTED & Isolasi Koperasi).
     */
    public function test_scenario_3_and_reporting_isolation_and_draft_exclusion(): void
    {
        // 1. Buat Jurnal DRAFT dengan diskon dan denda
        // Pokok 60jt, Bunga Bruto 3jt, Denda 200rb, Diskon 500rb, Kas 62,7jt
        $draftJournal = $this->journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => now()->toDateString(),
            'description' => 'Draft Pelunasan Diskon dan Denda',
        ], [
            ['account_id' => $this->cash->id, 'debit' => 62_700_000, 'credit' => 0, 'description' => 'Kas Masuk'],
            ['account_id' => $this->discount->id, 'debit' => 500_000, 'credit' => 0, 'description' => 'Diskon Pelunasan'],
            ['account_id' => $this->receivable->id, 'debit' => 0, 'credit' => 60_000_000, 'description' => 'Piutang Pokok'],
            ['account_id' => $this->grossInterest->id, 'debit' => 0, 'credit' => 3_000_000, 'description' => 'Bunga Bruto'],
            ['account_id' => $this->penalty->id, 'debit' => 0, 'credit' => 200_000, 'description' => 'Denda'],
        ], $this->accountingUser->id);

        $this->assertSame(JournalEntry::STATUS_DRAFT, $draftJournal->status);

        // Pastikan DRAFT journal TIDAK muncul di laporan keuangan
        $beforeReport = $this->reportService->incomeStatement(now()->toDateString(), now()->toDateString(), AccountingContext::SHM);
        $this->assertEquals(0.0, $beforeReport['summary']['total_revenue']);

        // 2. Post jurnal
        $postedJournal = $this->journalService->post($draftJournal, $this->accountingUser->id);
        $this->assertSame(JournalEntry::STATUS_POSTED, $postedJournal->status);

        // Setelah diposting, laporan harus menghitung Gross Bunga (3jt) + Denda (200rb) - Diskon (500rb) = 2,7jt
        $afterReport = $this->reportService->incomeStatement(now()->toDateString(), now()->toDateString(), AccountingContext::SHM);
        $this->assertEquals(2_700_000.0, $afterReport['summary']['total_revenue']);

        // Pastikan laporan KOPERASI sama sekali 0.0 (Isolasi entitas mutlak)
        $koperasiReport = $this->reportService->incomeStatement(now()->toDateString(), now()->toDateString(), AccountingContext::KOPERASI);
        $this->assertEquals(0.0, $koperasiReport['summary']['total_revenue']);
    }

    /**
     * Test E: Import XLSX dengan baris 4-1901 berhasil masuk sebagai DRAFT dan dapat diposting.
     */
    public function test_import_xlsx_accepts_41901_and_remains_draft_until_posted(): void
    {
        $xlsxService = app(SimpleXlsxService::class);

        $rows = [
            ['Tanggal', 'No Bukti', 'Kode Akun', 'Keterangan', 'Debit', 'Kredit'],
            [now()->toDateString(), 'JU-IMP-001', '1-1100', 'Penerimaan Kas Pelunasan', '62500000', '0'],
            [now()->toDateString(), 'JU-IMP-001', '4-1901', 'Diskon Pelunasan SHM (Contra)', '500000', '0'],
            [now()->toDateString(), 'JU-IMP-001', '1-1300', 'Pelunasan Pokok Pinjaman', '0', '60000000'],
            [now()->toDateString(), 'JU-IMP-001', '4-1101', 'Pendapatan Bunga Bruto', '0', '3000000'],
        ];

        $tempPath = sys_get_temp_dir().'/test_import_discount_'.uniqid().'.xlsx';
        $xlsxService->writeRows($tempPath, $rows, 'Jurnal_Pelunasan');

        $uploadedFile = new UploadedFile($tempPath, 'test_import_discount.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        // Lakukan import
        $batch = $this->importService->import($uploadedFile, $this->accountingUser->id);

        $this->assertInstanceOf(AccountingImportBatch::class, $batch);
        $this->assertSame(4, $batch->row_count);
        $this->assertSame(1, $batch->success_count);
        $this->assertSame('COMPLETED', $batch->status);

        // Ambil jurnal hasil impor
        $journal = JournalEntry::where('import_batch_id', $batch->id)->firstOrFail();
        $this->assertSame(JournalEntry::STATUS_DRAFT, $journal->status);
        $this->assertCount(4, $journal->lines);

        // Baris diskon tersimpan di 4-1901
        $discountLine = $journal->lines->firstWhere('account_id', $this->discount->id);
        $this->assertNotNull($discountLine);
        $this->assertEquals(500_000.0, (float) $discountLine->debit);
        $this->assertEquals(0.0, (float) $discountLine->credit);

        // Post jurnal impor secara manual
        $posted = $this->journalService->post($journal, $this->accountingUser->id);
        $this->assertSame(JournalEntry::STATUS_POSTED, $posted->status);

        if (file_exists($tempPath)) {
            unlink($tempPath);
        }
    }

    /**
     * Test F: Verifikasi bahwa pemanggilan jurnal otomatis operasional tetap NONAKTIF (Option A).
     */
    public function test_operational_services_do_not_automatically_create_or_post_journals(): void
    {
        $disbursementContent = file_get_contents(app_path('Services/Shm/ShmLoanDisbursementService.php'));
        $settlementContent = file_get_contents(app_path('Services/Shm/ShmLoanSettlementService.php'));
        $paymentContent = file_get_contents(app_path('Services/Shm/ShmRegularPaymentService.php'));
        $correctionContent = file_get_contents(app_path('Services/Shm/ShmSettlementCorrectionService.php'));

        $this->assertStringContainsString('// $this->autoJournalService->createDisbursementJournal', $disbursementContent);
        $this->assertStringContainsString('// $this->autoJournalService->createSettlementJournal', $settlementContent);
        $this->assertStringContainsString('// $this->autoJournalService->createPaymentJournal', $paymentContent);
        $this->assertStringContainsString('// $this->autoJournalService->reverseSettlementJournal', $correctionContent);
    }
}
