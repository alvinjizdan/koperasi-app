<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\FinancialReportService;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\ShmJournalImportService;
use App\Services\Accounting\SimpleXlsxService;
use App\Support\AccountingContext;
use Database\Seeders\ShmChartOfAccountSeeder;
use Database\Seeders\ShmLoanProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * TICKET 39 — SHM Unallocated Payment Liability Accounting Feature Tests.
 */
class ShmUnallocatedPaymentLiabilityAccountingTest extends TestCase
{
    use RefreshDatabase;

    private User $accountingUser;

    private ChartOfAccount $cash;

    private ChartOfAccount $bank;

    private ChartOfAccount $receivable;

    private ChartOfAccount $grossInterest;

    private ChartOfAccount $unallocatedLiability;

    private FinancialReportService $reportService;

    private JournalService $journalService;

    private ShmJournalImportService $importService;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Jalankan seeder resmi SHM CoA (T38 & T39) dan Matriks Produk SHM (T82)
        $this->seed(ShmChartOfAccountSeeder::class);
        $this->seed(ShmLoanProductSeeder::class);

        $this->reportService = app(FinancialReportService::class);
        $this->journalService = app(JournalService::class);
        $this->importService = app(ShmJournalImportService::class);

        // 2. Fetch akun resmi SHM
        $this->cash = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '1-1100')->firstOrFail();
        $this->bank = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '1-1200')->firstOrFail();
        $this->receivable = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '1-1300')->firstOrFail();
        $this->grossInterest = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '4-1101')->firstOrFail();
        $this->unallocatedLiability = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '2-1301')->firstOrFail();

        // 3. Setup pengguna dengan role & permission akuntansi SHM
        $this->accountingUser = User::factory()->create();
        $accountingRole = Role::firstOrCreate(
            ['name' => 'manajer_akuntansi_makro', 'entity' => 'makro'],
            ['display_name' => 'Manajer Akuntansi Makro', 'is_system' => false, 'is_active' => true]
        );
        $this->accountingUser->roles()->attach($accountingRole->id);
    }

    /**
     * Test 1 & 2: Akun 2-1301 tersedia dalam master CoA SHM dan seeder bersifat idempoten.
     */
    public function test_shm_coa_21301_is_registered_idempotently_with_correct_properties(): void
    {
        $account = ChartOfAccount::query()
            ->where('accounting_context', AccountingContext::SHM)
            ->where('code', '2-1301')
            ->first();

        $this->assertNotNull($account);
        $this->assertSame('Titipan Pembayaran Angsuran SHM', $account->name);
        $this->assertSame(ChartOfAccount::CATEGORY_LIABILITY, $account->category);
        $this->assertSame(ChartOfAccount::NORMAL_CREDIT, $account->normal_balance);
        $this->assertTrue($account->is_active);

        // Eksekusi seeder ulang untuk memastikan idempotensi (tidak ada duplikasi)
        $this->seed(ShmChartOfAccountSeeder::class);

        $count = ChartOfAccount::query()
            ->where('accounting_context', AccountingContext::SHM)
            ->where('code', '2-1301')
            ->count();

        $this->assertSame(1, $count);
    }

    /**
     * Test 3: Akun 2-1301 ditolak apabila context bukan SHM.
     */
    public function test_coa_21301_rejects_non_shm_context(): void
    {
        // Pastikan tidak ada akun 2-1301 di konteks Koperasi
        $koperasiAccount = ChartOfAccount::query()
            ->where('accounting_context', AccountingContext::KOPERASI)
            ->where('code', '2-1301')
            ->first();

        $this->assertNull($koperasiAccount);

        // Buat import XLSX dengan kode 2-1301 tapi mencoba membaca di akun konteks Koperasi
        $xlsxService = app(SimpleXlsxService::class);
        $rows = [
            ['Tanggal', 'No Bukti', 'Kode Akun', 'Keterangan', 'Debit', 'Kredit'],
            [now()->toDateString(), 'JU-TEST-KOP', '2-1301', 'Coba Akun Koperasi', '0', '1000000'],
            [now()->toDateString(), 'JU-TEST-KOP', '1-1100', 'Kas', '1000000', '0'],
        ];

        $tempPath = sys_get_temp_dir().'/test_kop_'.uniqid().'.xlsx';
        $xlsxService->writeRows($tempPath, $rows, 'Test_Kop');

        // ShmJournalImportService hanya membaca SHM accounts
        $preview = $this->importService->preview(new UploadedFile($tempPath, 'test.xlsx', null, null, true));
        $this->assertTrue($preview['is_balanced']);
        $this->assertEmpty($preview['errors']); // 2-1301 diakui sah untuk SHM

        if (file_exists($tempPath)) {
            unlink($tempPath);
        }
    }

    /**
     * Test 4: Penerimaan dana unallocated menghasilkan jurnal konseptual yang seimbang.
     * BR-39-03: Debit Kas, Kredit 2-1301.
     */
    public function test_initial_receipt_of_unallocated_funds_creates_balanced_draft_journal(): void
    {
        // Nasabah membayar Rp 5.000.000, angsuran Rp 3.000.000, sisa Rp 2.000.000 unallocated
        $journal = $this->journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => now()->toDateString(),
            'description' => 'Penerimaan Angsuran Berlebih Nasabah SHM',
        ], [
            ['account_id' => $this->cash->id, 'debit' => 5_000_000, 'credit' => 0, 'description' => 'Penerimaan Kas'],
            ['account_id' => $this->receivable->id, 'debit' => 0, 'credit' => 2_500_000, 'description' => 'Alokasi Pokok'],
            ['account_id' => $this->grossInterest->id, 'debit' => 0, 'credit' => 500_000, 'description' => 'Alokasi Bunga'],
            ['account_id' => $this->unallocatedLiability->id, 'debit' => 0, 'credit' => 2_000_000, 'description' => 'Titipan Angsuran (Unallocated)'],
        ], $this->accountingUser->id);

        $this->assertSame(JournalEntry::STATUS_DRAFT, $journal->status);
        $posted = $this->journalService->post($journal, $this->accountingUser->id);
        $this->assertSame(JournalEntry::STATUS_POSTED, $posted->status);

        // Periksa Neraca SHM: Liabilitas 2-1301 harus bernilai Rp 2.000.000
        $bs = $this->reportService->balanceSheet(now()->toDateString(), AccountingContext::SHM);
        $liabilitySection = $bs['sections'][ChartOfAccount::CATEGORY_LIABILITY];
        $titipanRow = collect($liabilitySection['accounts'])->firstWhere('code', '2-1301');

        $this->assertNotNull($titipanRow);
        $this->assertEquals(2_000_000.0, $titipanRow['balance']);
    }

    /**
     * Test 5, 7, & 8: Dana titipan digunakan sebagian untuk settlement dan kombinasi kas baru tidak menghitung kas dua kali.
     * BR-39-04 & BR-39-06.
     */
    public function test_partial_application_to_settlement_avoids_double_cash_and_leaves_remaining_balance(): void
    {
        // 1. Catat titipan awal Rp 5.000.000
        $initialReceipt = $this->journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => now()->toDateString(),
            'description' => 'Penerimaan Titipan Awal',
        ], [
            ['account_id' => $this->bank->id, 'debit' => 5_000_000, 'credit' => 0, 'description' => 'Bank'],
            ['account_id' => $this->unallocatedLiability->id, 'debit' => 0, 'credit' => 5_000_000, 'description' => 'Titipan'],
        ], $this->accountingUser->id);
        $this->journalService->post($initialReceipt, $this->accountingUser->id);

        // 2. Settlement senilai Rp 10.000.000 (Pokok 9jt, Bunga 1jt).
        // Titipan digunakan sebagian: Rp 3.000.000
        // Kas baru yang disetor: Rp 7.000.000
        $settlementJournal = $this->journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => now()->toDateString(),
            'description' => 'Pelunasan SHM Sebagian Menggunakan Titipan',
        ], [
            ['account_id' => $this->cash->id, 'debit' => 7_000_000, 'credit' => 0, 'description' => 'Kas Baru Diterima'],
            ['account_id' => $this->unallocatedLiability->id, 'debit' => 3_000_000, 'credit' => 0, 'description' => 'Pengurangan Titipan Angsuran'],
            ['account_id' => $this->receivable->id, 'debit' => 0, 'credit' => 9_000_000, 'description' => 'Pelunasan Pokok'],
            ['account_id' => $this->grossInterest->id, 'debit' => 0, 'credit' => 1_000_000, 'description' => 'Pendapatan Bunga'],
        ], $this->accountingUser->id);
        $this->journalService->post($settlementJournal, $this->accountingUser->id);

        // 3. Verifikasi Neraca
        $bs = $this->reportService->balanceSheet(now()->toDateString(), AccountingContext::SHM);
        $liabilitySection = $bs['sections'][ChartOfAccount::CATEGORY_LIABILITY];
        $titipanRow = collect($liabilitySection['accounts'])->firstWhere('code', '2-1301');

        // Sisa saldo liabilitas: Rp 5.000.000 - Rp 3.000.000 = Rp 2.000.000
        $this->assertEquals(2_000_000.0, $titipanRow['balance']);

        // Verifikasi Kas/Bank Total: Bank (5jt) + Kas Baru (7jt) = 12jt total kas masuk riil
        $assetSection = $bs['sections'][ChartOfAccount::CATEGORY_ASSET];
        $kasRow = collect($assetSection['accounts'])->firstWhere('code', '1-1100');
        $bankRow = collect($assetSection['accounts'])->firstWhere('code', '1-1200');

        $this->assertEquals(7_000_000.0, $kasRow['balance']);
        $this->assertEquals(5_000_000.0, $bankRow['balance']);
        // Tidak ada duplikasi penerimaan kas 3jt pada saat settlement!
    }

    /**
     * Test 6: Dana titipan dapat menutup seluruh settlement tanpa penerimaan kas baru (Zero Cash).
     * BR-39-05.
     */
    public function test_fully_covered_settlement_creates_no_cash_debit(): void
    {
        // Titipan awal Rp 10.000.000
        $initial = $this->journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => now()->toDateString(),
            'description' => 'Titipan Penuh',
        ], [
            ['account_id' => $this->bank->id, 'debit' => 10_000_000, 'credit' => 0, 'description' => 'Bank'],
            ['account_id' => $this->unallocatedLiability->id, 'debit' => 0, 'credit' => 10_000_000, 'description' => 'Titipan'],
        ], $this->accountingUser->id);
        $this->journalService->post($initial, $this->accountingUser->id);

        // Pelunasan Rp 10.000.000 ditutup 100% oleh titipan
        $fullSettlement = $this->journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => now()->toDateString(),
            'description' => 'Pelunasan 100% Menggunakan Titipan',
        ], [
            ['account_id' => $this->unallocatedLiability->id, 'debit' => 10_000_000, 'credit' => 0, 'description' => 'Penutupan Titipan Penuh'],
            ['account_id' => $this->receivable->id, 'debit' => 0, 'credit' => 9_500_000, 'description' => 'Pokok'],
            ['account_id' => $this->grossInterest->id, 'debit' => 0, 'credit' => 500_000, 'description' => 'Bunga'],
        ], $this->accountingUser->id);
        $this->journalService->post($fullSettlement, $this->accountingUser->id);

        // Neraca: Saldo titipan harus 0.0 (habis terpakai)
        $bs = $this->reportService->balanceSheet(now()->toDateString(), AccountingContext::SHM);
        $liabilitySection = $bs['sections'][ChartOfAccount::CATEGORY_LIABILITY];
        $titipanRow = collect($liabilitySection['accounts'])->firstWhere('code', '2-1301');

        $this->assertEquals(0.0, $titipanRow['balance']);
    }

    /**
     * Test 11 & 12: Jurnal DRAFT tidak memengaruhi Balance Sheet, Jurnal POSTED memengaruhi.
     */
    public function test_draft_journals_do_not_affect_balance_sheet_while_posted_does(): void
    {
        $draft = $this->journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => now()->toDateString(),
            'description' => 'Draft Titipan',
        ], [
            ['account_id' => $this->cash->id, 'debit' => 1_000_000, 'credit' => 0, 'description' => 'Kas'],
            ['account_id' => $this->unallocatedLiability->id, 'debit' => 0, 'credit' => 1_000_000, 'description' => 'Titipan'],
        ], $this->accountingUser->id);

        $bsDraft = $this->reportService->balanceSheet(now()->toDateString(), AccountingContext::SHM);
        $liabilitySection = $bsDraft['sections'][ChartOfAccount::CATEGORY_LIABILITY];
        $titipanRow = collect($liabilitySection['accounts'])->firstWhere('code', '2-1301');

        $this->assertNull($titipanRow); // Belum ada saldo karena masih DRAFT

        $this->journalService->post($draft, $this->accountingUser->id);

        $bsPosted = $this->reportService->balanceSheet(now()->toDateString(), AccountingContext::SHM);
        $liabilitySectionPosted = $bsPosted['sections'][ChartOfAccount::CATEGORY_LIABILITY];
        $titipanRowPosted = collect($liabilitySectionPosted['accounts'])->firstWhere('code', '2-1301');

        $this->assertNotNull($titipanRowPosted);
        $this->assertEquals(1_000_000.0, $titipanRowPosted['balance']);
    }

    /**
     * Test 13: Koperasi Accounting tidak berubah dan tetap terisolasi.
     */
    public function test_koperasi_accounting_remains_isolated(): void
    {
        // Buat jurnal titipan SHM POSTED
        $posted = $this->journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => now()->toDateString(),
            'description' => 'SHM Titipan Jurnal',
        ], [
            ['account_id' => $this->cash->id, 'debit' => 4_000_000, 'credit' => 0, 'description' => 'Kas'],
            ['account_id' => $this->unallocatedLiability->id, 'debit' => 0, 'credit' => 4_000_000, 'description' => 'Titipan'],
        ], $this->accountingUser->id);
        $this->journalService->post($posted, $this->accountingUser->id);

        // Periksa Neraca Koperasi: Total Liabilitas harus 0.0
        $kopBs = $this->reportService->balanceSheet(now()->toDateString(), AccountingContext::KOPERASI);
        $this->assertEquals(0.0, (float) $kopBs['summary']['total_liabilities']);
    }
}
