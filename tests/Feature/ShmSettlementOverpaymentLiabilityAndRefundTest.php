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
 * TICKET 40 — SHM Settlement Overpayment Liability & Refund Accounting Tests.
 */
class ShmSettlementOverpaymentLiabilityAndRefundTest extends TestCase
{
    use RefreshDatabase;

    private User $accountingUser;

    private ChartOfAccount $cash;

    private ChartOfAccount $bank;

    private ChartOfAccount $receivable;

    private ChartOfAccount $grossInterest;

    private ChartOfAccount $penalty;

    private ChartOfAccount $discount;

    private ChartOfAccount $unallocatedLiability;

    private ChartOfAccount $overpaymentLiability;

    private FinancialReportService $reportService;

    private JournalService $journalService;

    private ShmJournalImportService $importService;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Jalankan seeder resmi SHM CoA (T38, T39, & T40) dan Matriks Produk SHM (T82)
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
        $this->penalty = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '4-1103')->firstOrFail();
        $this->discount = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '4-1901')->firstOrFail();
        $this->unallocatedLiability = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '2-1301')->firstOrFail();
        $this->overpaymentLiability = ChartOfAccount::query()->where('accounting_context', AccountingContext::SHM)->where('code', '2-1302')->firstOrFail();

        // 3. Setup pengguna dengan role & permission akuntansi SHM
        $this->accountingUser = User::factory()->create();
        $accountingRole = Role::firstOrCreate(
            ['name' => 'manajer_akuntansi_makro', 'entity' => 'makro'],
            ['display_name' => 'Manajer Akuntansi Makro', 'is_system' => false, 'is_active' => true]
        );
        $this->accountingUser->roles()->attach($accountingRole->id);
    }

    /**
     * Test 1, 2, & 3: Akun 2-1302 terdaftar pada master CoA SHM, seeder idempoten, kategori liability, normal credit.
     */
    public function test_shm_coa_21302_is_registered_idempotently_with_correct_properties(): void
    {
        $account = ChartOfAccount::query()
            ->where('accounting_context', AccountingContext::SHM)
            ->where('code', '2-1302')
            ->first();

        $this->assertNotNull($account);
        $this->assertSame('Kelebihan Pembayaran Pelunasan SHM', $account->name);
        $this->assertSame(ChartOfAccount::CATEGORY_LIABILITY, $account->category);
        $this->assertSame(ChartOfAccount::NORMAL_CREDIT, $account->normal_balance);
        $this->assertTrue($account->is_active);

        // Eksekusi seeder ulang untuk memastikan idempotensi (tidak ada duplikasi)
        $this->seed(ShmChartOfAccountSeeder::class);

        $count = ChartOfAccount::query()
            ->where('accounting_context', AccountingContext::SHM)
            ->where('code', '2-1302')
            ->count();

        $this->assertSame(1, $count);
    }

    /**
     * Test 4: Akun 2-1302 tidak tercampur dengan Koperasi.
     */
    public function test_coa_21302_is_isolated_from_koperasi(): void
    {
        $koperasiAccount = ChartOfAccount::query()
            ->where('accounting_context', AccountingContext::KOPERASI)
            ->where('code', '2-1302')
            ->first();

        $this->assertNull($koperasiAccount);
    }

    /**
     * Test 5 & 6: Pengakuan overpayment menghasilkan jurnal seimbang dan TIDAK diakui sebagai pendapatan tambahan.
     * BR-40-03.
     */
    public function test_settlement_overpayment_recognized_as_liability_and_not_revenue(): void
    {
        // Settlement: Pokok 20jt, Bunga 2jt (Total kewajiban 22jt). Nasabah mentransfer 23jt (Overpayment 1jt).
        $journal = $this->journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => now()->toDateString(),
            'description' => 'Pelunasan SHM dengan Lebih Bayar (Debitur A)',
        ], [
            ['account_id' => $this->bank->id, 'debit' => 23_000_000, 'credit' => 0, 'description' => 'Transfer Masuk Bank'],
            ['account_id' => $this->receivable->id, 'debit' => 0, 'credit' => 20_000_000, 'description' => 'Pelunasan Pokok'],
            ['account_id' => $this->grossInterest->id, 'debit' => 0, 'credit' => 2_000_000, 'description' => 'Pendapatan Bunga'],
            ['account_id' => $this->overpaymentLiability->id, 'debit' => 0, 'credit' => 1_000_000, 'description' => 'Kelebihan Bayar Pelunasan (Liability)'],
        ], $this->accountingUser->id);

        $this->assertSame(JournalEntry::STATUS_DRAFT, $journal->status);
        $posted = $this->journalService->post($journal, $this->accountingUser->id);
        $this->assertSame(JournalEntry::STATUS_POSTED, $posted->status);

        // 1. Verifikasi Laba Rugi: Pendapatan Bunga HANYA 2jt (Bukan 3jt!)
        $incomeReport = $this->reportService->incomeStatement(now()->toDateString(), now()->toDateString(), AccountingContext::SHM);
        $this->assertEquals(2_000_000.0, (float) $incomeReport['summary']['total_revenue']);

        // 2. Verifikasi Neraca: Liabilitas 2-1302 bertambah 1jt
        $bs = $this->reportService->balanceSheet(now()->toDateString(), AccountingContext::SHM);
        $liabilitySection = $bs['sections'][ChartOfAccount::CATEGORY_LIABILITY];
        $overpaymentRow = collect($liabilitySection['accounts'])->firstWhere('code', '2-1302');

        $this->assertNotNull($overpaymentRow);
        $this->assertEquals(1_000_000.0, (float) $overpaymentRow['balance']);
    }

    /**
     * Test 7: Dana titipan 2-1301 dan lebih bayar 2-1302 tidak saling campur dan tidak ada double counting kas.
     * BR-40-01 & BR-40-03.
     */
    public function test_combined_unallocated_and_overpayment_prevents_double_counting(): void
    {
        // Kewajiban pelunasan kotor: Pokok 50jt, Bunga 5jt, Denda 500rb = 55,5jt
        // Ada titipan angsuran 2-1301: 5jt -> Sisa tagihan bersih = 50,5jt
        // Nasabah membayar: 51jt (Kelebihan bayar = 500rb)
        $compoundJournal = $this->journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => now()->toDateString(),
            'description' => 'Pelunasan Majemuk (Titipan 2-1301 dan Lebih Bayar 2-1302)',
        ], [
            ['account_id' => $this->bank->id, 'debit' => 51_000_000, 'credit' => 0, 'description' => 'Penerimaan Kas Bank Baru'],
            ['account_id' => $this->unallocatedLiability->id, 'debit' => 5_000_000, 'credit' => 0, 'description' => 'Pengurangan Titipan Angsuran'],
            ['account_id' => $this->receivable->id, 'debit' => 0, 'credit' => 50_000_000, 'description' => 'Pokok Pinjaman'],
            ['account_id' => $this->grossInterest->id, 'debit' => 0, 'credit' => 5_000_000, 'description' => 'Pendapatan Bunga'],
            ['account_id' => $this->penalty->id, 'debit' => 0, 'credit' => 500_000, 'description' => 'Pendapatan Denda'],
            ['account_id' => $this->overpaymentLiability->id, 'debit' => 0, 'credit' => 500_000, 'description' => 'Kelebihan Bayar Pelunasan'],
        ], $this->accountingUser->id);

        $posted = $this->journalService->post($compoundJournal, $this->accountingUser->id);
        $this->assertSame(JournalEntry::STATUS_POSTED, $posted->status);

        // Verifikasi Neraca
        $bs = $this->reportService->balanceSheet(now()->toDateString(), AccountingContext::SHM);
        $liabilitySection = $bs['sections'][ChartOfAccount::CATEGORY_LIABILITY];

        $titipanRow = collect($liabilitySection['accounts'])->firstWhere('code', '2-1301');
        $overpaymentRow = collect($liabilitySection['accounts'])->firstWhere('code', '2-1302');

        // Titipan berkurang 5jt (balance = -5jt jika tanpa saldo awal, atau net 0 jika ada saldo awal)
        $this->assertEquals(-5_000_000.0, (float) $titipanRow['balance']);
        // Lebih bayar bertambah 500rb
        $this->assertEquals(500_000.0, (float) $overpaymentRow['balance']);
    }

    /**
     * Test 8 & 9: Partial refund dan Full refund pengembalian kelebihan bayar.
     * BR-40-04, BR-40-06, BR-40-07.
     */
    public function test_partial_and_full_refund_journal_mechanics(): void
    {
        // 1. Akui Overpayment awal Rp 5.000.000
        $initial = $this->journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => now()->toDateString(),
            'description' => 'Overpayment Pelunasan Rp 5jt',
        ], [
            ['account_id' => $this->bank->id, 'debit' => 25_000_000, 'credit' => 0, 'description' => 'Bank'],
            ['account_id' => $this->receivable->id, 'debit' => 0, 'credit' => 20_000_000, 'description' => 'Pokok'],
            ['account_id' => $this->overpaymentLiability->id, 'debit' => 0, 'credit' => 5_000_000, 'description' => 'Overpayment Liability'],
        ], $this->accountingUser->id);
        $this->journalService->post($initial, $this->accountingUser->id);

        // 2. Partial Refund Rp 2.000.000 ditransfer balik ke rekening nasabah
        $partialRefund = $this->journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => now()->toDateString(),
            'description' => 'Pengembalian Sebagian Kelebihan Bayar Pelunasan (Refund Tahap 1)',
        ], [
            ['account_id' => $this->overpaymentLiability->id, 'debit' => 2_000_000, 'credit' => 0, 'description' => 'Debit Liabilitas Refund'],
            ['account_id' => $this->bank->id, 'debit' => 0, 'credit' => 2_000_000, 'description' => 'Kredit Bank Payout'],
        ], $this->accountingUser->id);
        $this->journalService->post($partialRefund, $this->accountingUser->id);

        // Cek Neraca: Saldo 2-1302 harus tinggal Rp 3.000.000
        $bs1 = $this->reportService->balanceSheet(now()->toDateString(), AccountingContext::SHM);
        $overpaymentRow1 = collect($bs1['sections'][ChartOfAccount::CATEGORY_LIABILITY]['accounts'])->firstWhere('code', '2-1302');
        $this->assertEquals(3_000_000.0, (float) $overpaymentRow1['balance']);

        // 3. Full Refund sisa Rp 3.000.000
        $fullRefund = $this->journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => now()->toDateString(),
            'description' => 'Pengembalian Sisa Kelebihan Bayar Pelunasan (Refund Tahap 2)',
        ], [
            ['account_id' => $this->overpaymentLiability->id, 'debit' => 3_000_000, 'credit' => 0, 'description' => 'Debit Liabilitas Sisa'],
            ['account_id' => $this->bank->id, 'debit' => 0, 'credit' => 3_000_000, 'description' => 'Kredit Bank Payout'],
        ], $this->accountingUser->id);
        $this->journalService->post($fullRefund, $this->accountingUser->id);

        // Cek Neraca: Saldo 2-1302 harus 0.0 (Lunas / Habis)
        $bs2 = $this->reportService->balanceSheet(now()->toDateString(), AccountingContext::SHM);
        $overpaymentRow2 = collect($bs2['sections'][ChartOfAccount::CATEGORY_LIABILITY]['accounts'])->firstWhere('code', '2-1302');
        $this->assertEquals(0.0, (float) $overpaymentRow2['balance']);
    }

    /**
     * Test 12 & 13: Jurnal Refund DRAFT tidak memengaruhi Neraca, POSTED memengaruhi.
     */
    public function test_refund_draft_does_not_affect_balance_sheet_while_posted_does(): void
    {
        // 1. Setup liabilitas 1jt
        $initial = $this->journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => now()->toDateString(),
            'description' => 'Setup Overpayment',
        ], [
            ['account_id' => $this->bank->id, 'debit' => 1_000_000, 'credit' => 0, 'description' => 'Bank'],
            ['account_id' => $this->overpaymentLiability->id, 'debit' => 0, 'credit' => 1_000_000, 'description' => 'Liability'],
        ], $this->accountingUser->id);
        $this->journalService->post($initial, $this->accountingUser->id);

        // 2. Buat Draft Refund Rp 1.000.000
        $draftRefund = $this->journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => now()->toDateString(),
            'description' => 'Draft Pengembalian Overpayment',
        ], [
            ['account_id' => $this->overpaymentLiability->id, 'debit' => 1_000_000, 'credit' => 0, 'description' => 'Debit Liabilitas'],
            ['account_id' => $this->bank->id, 'debit' => 0, 'credit' => 1_000_000, 'description' => 'Kredit Bank'],
        ], $this->accountingUser->id);

        // Selama masih DRAFT, saldo di Neraca masih tetap Rp 1.000.000
        $bsDraft = $this->reportService->balanceSheet(now()->toDateString(), AccountingContext::SHM);
        $rowDraft = collect($bsDraft['sections'][ChartOfAccount::CATEGORY_LIABILITY]['accounts'])->firstWhere('code', '2-1302');
        $this->assertEquals(1_000_000.0, (float) $rowDraft['balance']);

        // 3. Post Refund
        $this->journalService->post($draftRefund, $this->accountingUser->id);

        // Setelah POSTED, saldo berkurang menjadi 0.0
        $bsPosted = $this->reportService->balanceSheet(now()->toDateString(), AccountingContext::SHM);
        $rowPosted = collect($bsPosted['sections'][ChartOfAccount::CATEGORY_LIABILITY]['accounts'])->firstWhere('code', '2-1302');
        $this->assertEquals(0.0, (float) $rowPosted['balance']);
    }

    /**
     * Test 14 & 15: Dukungan SHM Import Center XLSX dan isolasi Koperasi.
     */
    public function test_import_xlsx_accepts_21302_and_koperasi_remains_isolated(): void
    {
        $xlsxService = app(SimpleXlsxService::class);

        $rows = [
            ['Tanggal', 'No Bukti', 'Kode Akun', 'Keterangan', 'Debit', 'Kredit'],
            [now()->toDateString(), 'JU-REFUND-001', '2-1302', 'Refund Kelebihan Bayar Nasabah', '500000', '0'],
            [now()->toDateString(), 'JU-REFUND-001', '1-1200', 'Bank Operasional SHM', '0', '500000'],
        ];

        $tempPath = sys_get_temp_dir().'/test_import_refund_'.uniqid().'.xlsx';
        $xlsxService->writeRows($tempPath, $rows, 'Jurnal_Refund');

        $uploadedFile = new UploadedFile($tempPath, 'test_import_refund.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        // Import berkas
        $batch = $this->importService->import($uploadedFile, $this->accountingUser->id);
        $this->assertSame(2, $batch->row_count);
        $this->assertSame(1, $batch->success_count);

        $journal = JournalEntry::where('import_batch_id', $batch->id)->firstOrFail();
        $this->assertSame(JournalEntry::STATUS_DRAFT, $journal->status);

        // Post jurnal impor
        $posted = $this->journalService->post($journal, $this->accountingUser->id);
        $this->assertSame(JournalEntry::STATUS_POSTED, $posted->status);

        // Pastikan Koperasi tidak mencatat mutasi apa pun
        $kopBs = $this->reportService->balanceSheet(now()->toDateString(), AccountingContext::KOPERASI);
        $this->assertEquals(0.0, (float) $kopBs['summary']['total_liabilities']);

        if (file_exists($tempPath)) {
            unlink($tempPath);
        }
    }
}
