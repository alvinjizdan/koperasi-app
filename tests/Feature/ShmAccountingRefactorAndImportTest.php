<?php

namespace Tests\Feature;

use App\Models\AccountingImportBatch;
use App\Models\AccountingImportedReport;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Role;
use App\Models\ShmApplication;
use App\Models\ShmApproval;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanPayment;
use App\Models\ShmLoanProduct;
use App\Models\User;
use App\Services\Accounting\SimpleXlsxService;
use App\Services\Shm\ShmLoanDisbursementService;
use App\Services\Shm\ShmRegularPaymentService;
use App\Services\Shm\ShmRepaymentScheduleService;
use App\Support\AccountingContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithShmDisbursement;
use Tests\TestCase;

class ShmAccountingRefactorAndImportTest extends TestCase
{
    use InteractsWithShmDisbursement;
    use RefreshDatabase;

    private User $admin;

    private User $unauthorizedUser;

    private SimpleXlsxService $xlsx;

    private ShmCustomer $customer;

    private ShmLoanProduct $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->admin = $this->userWithRole('admin');
        $this->unauthorizedUser = $this->userWithRole('marketing');

        $this->seedShmSavingsProducts();
        $this->openCashierSessionFor($this->admin);

        $this->customer = $this->createShmCustomerWithMember();

        $this->product = ShmLoanProduct::firstOrCreate(
            ['code' => 'PJS'],
            ['name' => 'Pinjaman Siaga SHM', 'category' => ShmLoanProduct::CATEGORY_PERUMAHAN, 'is_active' => true]
        );
        $this->product->rateTiers()->delete();
        $this->product->rateTiers()->create([
            'min_tenor_months' => 12,
            'max_tenor_months' => 18,
            'rate_percent' => 1.30,
            'rate_period' => 'monthly',
            'calculation_method' => 'flat',
        ]);

        $this->xlsx = new SimpleXlsxService;

        // Ensure baseline SHM CoA exists
        ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::SHM, 'code' => '1-1100'],
            ['name' => 'Kas Operasional SHM', 'category' => 'asset', 'normal_balance' => 'debit', 'is_active' => true]
        );
        ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::SHM, 'code' => '1-1200'],
            ['name' => 'Piutang Pinjaman SHM', 'category' => 'asset', 'normal_balance' => 'debit', 'is_active' => true]
        );
        ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::SHM, 'code' => '4-1100'],
            ['name' => 'Pendapatan Bunga Pinjaman SHM', 'category' => 'revenue', 'normal_balance' => 'credit', 'is_active' => true]
        );

        // Koperasi CoA
        ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::KOPERASI, 'code' => '1-1100-KOP'],
            ['name' => 'Kas Koperasi', 'category' => 'asset', 'normal_balance' => 'debit', 'is_active' => true]
        );
    }

    private function createApprovedApplication(array $overrides = []): ShmLoanApplication
    {
        $mirror = ShmApplication::create([
            'application_number' => 'APP-MIRROR-'.Str::upper(Str::random(6)),
            'shm_customer_id' => $this->customer->id,
            'marketing_id' => $this->admin->id,
            'status' => 'approved',
        ]);

        ShmApproval::create([
            'shm_application_id' => $mirror->id,
            'decided_by' => $this->admin->id,
            'decided_at' => now(),
            'status' => ShmApproval::STATUS_APPROVED,
            'notes' => 'Disetujui untuk uji refactor akuntansi',
        ]);

        return ShmLoanApplication::create(array_merge([
            'application_number' => 'PENGAJ-REFACTOR-'.Str::upper(Str::random(8)),
            'shm_customer_id' => $this->customer->id,
            'shm_loan_product_id' => $this->product->id,
            'shm_application_id' => $mirror->id,
            'application_date' => now()->toDateString(),
            'principal' => 50000000,
            'approved_amount' => 50000000,
            'tenor_months' => 12,
            'first_due_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'marketing_id' => $this->admin->id,
            'status' => 'approved',
        ], $overrides));
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', $roleName)->value('id'));

        return $user;
    }

    /** 1. Test Disbursement does NOT create automatic journal */
    public function test_shm_disbursement_does_not_create_automatic_journal(): void
    {
        $initialJournalCount = JournalEntry::query()->where('accounting_context', AccountingContext::SHM)->count();

        $application = $this->createApprovedApplication();
        $disbursementService = app(ShmLoanDisbursementService::class);

        $loan = $disbursementService->disburse($application, $this->admin->id);

        $this->assertInstanceOf(ShmLoan::class, $loan);
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->status);

        // Assert NO new journal created for SHM context
        $newJournalCount = JournalEntry::query()->where('accounting_context', AccountingContext::SHM)->count();
        $this->assertSame($initialJournalCount, $newJournalCount);

        // Provision snapshot must remain intact (2% total)
        $this->assertEquals('1000000.00', (string) $loan->provision_fee);
        $this->assertNotNull($loan->office_provision_fee);
        $this->assertNotNull($loan->agent_commission_fee);
    }

    /** 2. Test Regular Payment does NOT create automatic journal */
    public function test_shm_regular_payment_does_not_create_automatic_journal(): void
    {
        $application = $this->createApprovedApplication();
        $disbursementService = app(ShmLoanDisbursementService::class);
        $loan = $disbursementService->disburse($application, $this->admin->id);

        // Generate schedule
        $scheduleService = app(ShmRepaymentScheduleService::class);
        $scheduleService->generate($loan, $this->admin->id);
        $loan->refresh();

        $initialJournalCount = JournalEntry::query()->where('accounting_context', AccountingContext::SHM)->count();

        $paymentService = app(ShmRegularPaymentService::class);
        $installment = $loan->installments()->first();

        $result = $paymentService->pay($loan, [
            'payment_date' => now()->toDateString(),
            'amount' => $installment->total_amount,
            'payment_method' => 'cash',
        ], $this->admin->id);

        $this->assertArrayHasKey('payment', $result);
        $this->assertInstanceOf(ShmLoanPayment::class, $result['payment']);

        $newJournalCount = JournalEntry::query()->where('accounting_context', AccountingContext::SHM)->count();
        $this->assertSame($initialJournalCount, $newJournalCount);
    }

    /** 3. Test Journal XLSX Import preview & store creates DRAFT entries */
    public function test_journal_xlsx_import_creates_draft_entries_and_batch_record(): void
    {
        $rows = [
            ['Tanggal', 'No Bukti', 'Kode Akun', 'Keterangan', 'Debit', 'Kredit'],
            ['2026-11-05', 'JU-001', '1-1100', 'Setoran modal', '5000000', '0'],
            ['2026-11-05', 'JU-001', '4-1100', 'Pendapatan', '0', '5000000'],
        ];

        $filePath = sys_get_temp_dir().'/test_import_journal_'.uniqid().'.xlsx';
        $this->xlsx->writeRows($filePath, $rows, 'Jurnal');

        $uploadedFile = new UploadedFile($filePath, 'test_journal.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        // 1. Preview request
        $previewResp = $this->actingAs($this->admin)->postJson(route('shm.accounting-import.preview.journal'), [
            'file' => $uploadedFile,
        ]);
        $previewResp->assertOk();
        $previewResp->assertJsonPath('total_journals', 1);
        $previewResp->assertJsonPath('is_balanced', true);
        $previewResp->assertJsonPath('errors', []);

        // 2. Import request
        $importResp = $this->actingAs($this->admin)->post(route('shm.accounting-import.store.journal'), [
            'file' => $uploadedFile,
        ]);
        $importResp->assertRedirect(route('shm.accounting-import.index'));

        // Batch created
        $batch = AccountingImportBatch::latest('id')->firstOrFail();
        $this->assertSame(AccountingContext::SHM, $batch->accounting_context);
        $this->assertSame(AccountingImportBatch::TYPE_JOURNAL, $batch->import_type);
        $this->assertSame(1, $batch->success_count);

        // Journal created in DRAFT status
        $entry = JournalEntry::where('import_batch_id', $batch->id)->firstOrFail();
        $this->assertSame(JournalEntry::STATUS_DRAFT, $entry->status);
        $this->assertSame('import_xlsx', $entry->posting_type);
        $this->assertSame(AccountingContext::SHM, $entry->accounting_context);
        $this->assertCount(2, $entry->lines);

        // 3. Duplicate import rejection
        $dupResp = $this->actingAs($this->admin)->post(route('shm.accounting-import.store.journal'), [
            'file' => $uploadedFile,
        ]);
        $dupResp->assertSessionHasErrors('file');

        @unlink($filePath);
    }

    /** 4. Test Unbalanced Journal XLSX Import is rejected */
    public function test_unbalanced_journal_xlsx_import_is_rejected(): void
    {
        $rows = [
            ['Tanggal', 'No Bukti', 'Kode Akun', 'Keterangan', 'Debit', 'Kredit'],
            ['2026-11-05', 'JU-UNBALANCED', '1-1100', 'Setoran modal', '5000000', '0'],
            ['2026-11-05', 'JU-UNBALANCED', '4-1100', 'Pendapatan', '0', '3000000'],
        ];

        $filePath = sys_get_temp_dir().'/test_unbalanced_journal_'.uniqid().'.xlsx';
        $this->xlsx->writeRows($filePath, $rows, 'Jurnal');

        $uploadedFile = new UploadedFile($filePath, 'test_unbalanced.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $importResp = $this->actingAs($this->admin)->post(route('shm.accounting-import.store.journal'), [
            'file' => $uploadedFile,
        ]);
        $importResp->assertSessionHasErrors('file');

        $this->assertSame(0, JournalEntry::where('posting_type', 'import_xlsx')->count());

        @unlink($filePath);
    }

    /** 5. Test Koperasi Account in SHM Journal Import is rejected */
    public function test_koperasi_account_in_shm_journal_import_is_rejected(): void
    {
        $rows = [
            ['Tanggal', 'No Bukti', 'Kode Akun', 'Keterangan', 'Debit', 'Kredit'],
            ['2026-11-05', 'JU-KOP', '1-1100-KOP', 'Akun Koperasi', '1000000', '0'],
            ['2026-11-05', 'JU-KOP', '4-1100', 'Pendapatan SHM', '0', '1000000'],
        ];

        $filePath = sys_get_temp_dir().'/test_kop_acc_'.uniqid().'.xlsx';
        $this->xlsx->writeRows($filePath, $rows, 'Jurnal');

        $uploadedFile = new UploadedFile($filePath, 'test_kop.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $importResp = $this->actingAs($this->admin)->post(route('shm.accounting-import.store.journal'), [
            'file' => $uploadedFile,
        ]);
        $importResp->assertSessionHasErrors('file');

        @unlink($filePath);
    }

    /** 6. Test Posting Draft Journal requires authorized permission */
    public function test_posting_draft_journal_authorization(): void
    {
        $entry = JournalEntry::create([
            'accounting_context' => AccountingContext::SHM,
            'journal_number' => 'JU-TEST-POST-01',
            'transaction_date' => now()->toDateString(),
            'description' => 'Test Post',
            'status' => JournalEntry::STATUS_DRAFT,
            'created_by' => $this->admin->id,
        ]);

        $cash = ChartOfAccount::shm()->where('code', '1-1100')->firstOrFail();
        $rev = ChartOfAccount::shm()->where('code', '4-1100')->firstOrFail();

        $entry->lines()->create(['account_id' => $cash->id, 'debit' => 100000, 'credit' => 0, 'line_order' => 1]);
        $entry->lines()->create(['account_id' => $rev->id, 'debit' => 0, 'credit' => 100000, 'line_order' => 2]);

        // Unauthorized user cannot post (403)
        $unauthResp = $this->actingAs($this->unauthorizedUser)->post(route('shm.accounting-import.post.journal', $entry->id));
        $unauthResp->assertForbidden();

        // Admin can post
        $authResp = $this->actingAs($this->admin)->post(route('shm.accounting-import.post.journal', $entry->id));
        $authResp->assertRedirect();

        $entry->refresh();
        $this->assertSame(JournalEntry::STATUS_POSTED, $entry->status);
        $this->assertSame($this->admin->id, $entry->posted_by);
    }

    /** 7. Test Report XLSX Import stores data isolated from ledger */
    public function test_report_xlsx_import_stores_isolated_report_data(): void
    {
        $initialJournalCount = JournalEntry::count();

        $rows = [
            ['Template Neraca SHM'],
            ['ASET', '', ''],
            ['1-1100', 'Kas Operasional SHM', '15000000.00'],
            ['TOTAL ASET', '', '15000000.00'],
            ['LIABILITAS', '', ''],
            ['TOTAL LIABILITAS', '', '0.00'],
            ['EKUITAS', '', ''],
            ['3-1100', 'Modal SHM', '15000000.00'],
            ['TOTAL EKUITAS', '', '15000000.00'],
        ];

        $filePath = sys_get_temp_dir().'/test_import_report_'.uniqid().'.xlsx';
        $this->xlsx->writeRows($filePath, $rows, 'Neraca');

        $uploadedFile = new UploadedFile($filePath, 'report_neraca.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        // Store Report
        $response = $this->actingAs($this->admin)->post(route('shm.accounting-import.store.report'), [
            'file' => $uploadedFile,
            'report_type' => AccountingImportBatch::REPORT_BALANCE_SHEET,
        ]);
        $response->assertRedirect(route('shm.accounting-import.index'));

        // Verify batch and report rows
        $batch = AccountingImportBatch::latest('id')->firstOrFail();
        $this->assertSame(AccountingImportBatch::TYPE_REPORT, $batch->import_type);
        $this->assertSame(AccountingImportBatch::REPORT_BALANCE_SHEET, $batch->report_type);

        $reportRecords = AccountingImportedReport::where('batch_id', $batch->id)->get();
        $this->assertNotEmpty($reportRecords);

        // Verify NO journal entries created
        $this->assertSame($initialJournalCount, JournalEntry::count());

        @unlink($filePath);
    }

    /** 8. Test Multiple Rows with Same Ref form a single Journal Entry, and different refs form separate entries */
    public function test_journal_import_groups_multiple_rows_by_reference(): void
    {
        $rows = [
            ['Tanggal', 'No Bukti', 'Kode Akun', 'Keterangan', 'Debit', 'Kredit'],
            // Jurnal 1: JU-GROUP-01 (3 baris)
            ['2026-11-05', 'JU-GROUP-01', '1-1100', 'Kas diterima', '10000000', '0'],
            ['2026-11-05', 'JU-GROUP-01', '4-1100', 'Pendapatan Bunga Bagian 1', '0', '6000000'],
            ['2026-11-05', 'JU-GROUP-01', '4-1100', 'Pendapatan Bunga Bagian 2', '0', '4000000'],
            // Jurnal 2: JU-GROUP-02 (2 baris terpisah)
            ['2026-11-05', 'JU-GROUP-02', '1-1100', 'Kas transaksi 2', '2000000', '0'],
            ['2026-11-05', 'JU-GROUP-02', '4-1100', 'Pendapatan transaksi 2', '0', '2000000'],
        ];

        $filePath = sys_get_temp_dir().'/test_group_journal_'.uniqid().'.xlsx';
        $this->xlsx->writeRows($filePath, $rows, 'Jurnal');

        $uploadedFile = new UploadedFile($filePath, 'test_group.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->actingAs($this->admin)->post(route('shm.accounting-import.store.journal'), [
            'file' => $uploadedFile,
        ]);
        $response->assertRedirect(route('shm.accounting-import.index'));

        $batch = AccountingImportBatch::latest('id')->firstOrFail();
        $this->assertSame(2, $batch->success_count);

        $j1 = JournalEntry::where('import_batch_id', $batch->id)->where('description', 'like', '%JU-GROUP-01%')->firstOrFail();
        $this->assertCount(3, $j1->lines);
        $this->assertEquals(10000000.0, $j1->totalDebit());
        $this->assertEquals(10000000.0, $j1->totalCredit());

        $j2 = JournalEntry::where('import_batch_id', $batch->id)->where('description', 'like', '%JU-GROUP-02%')->firstOrFail();
        $this->assertCount(2, $j2->lines);
        $this->assertEquals(2000000.0, $j2->totalDebit());
        $this->assertEquals(2000000.0, $j2->totalCredit());

        @unlink($filePath);
    }

    /** 9. Test Conflicting Dates under Same Reference is rejected */
    public function test_conflicting_dates_under_same_reference_is_rejected(): void
    {
        $rows = [
            ['Tanggal', 'No Bukti', 'Kode Akun', 'Keterangan', 'Debit', 'Kredit'],
            ['2026-11-05', 'JU-CONFLICT', '1-1100', 'Kas', '5000000', '0'],
            ['2026-11-06', 'JU-CONFLICT', '4-1100', 'Pendapatan tanggal beda', '0', '5000000'],
        ];

        $filePath = sys_get_temp_dir().'/test_conflict_date_'.uniqid().'.xlsx';
        $this->xlsx->writeRows($filePath, $rows, 'Jurnal');

        $uploadedFile = new UploadedFile($filePath, 'test_conflict.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->actingAs($this->admin)->post(route('shm.accounting-import.store.journal'), [
            'file' => $uploadedFile,
        ]);
        $response->assertSessionHasErrors('file');

        @unlink($filePath);
    }

    /** 10. Test Inactive SHM Account is rejected */
    public function test_inactive_shm_account_is_rejected(): void
    {
        $inactiveAcc = ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::SHM, 'code' => '1-9999-INACTIVE'],
            ['name' => 'Akun SHM Nonaktif', 'category' => 'asset', 'normal_balance' => 'debit', 'is_active' => false]
        );
        $inactiveAcc->is_active = false;
        $inactiveAcc->save();

        $rows = [
            ['Tanggal', 'No Bukti', 'Kode Akun', 'Keterangan', 'Debit', 'Kredit'],
            ['2026-11-05', 'JU-INACTIVE', '1-9999-INACTIVE', 'Akun nonaktif', '1000000', '0'],
            ['2026-11-05', 'JU-INACTIVE', '4-1100', 'Pendapatan', '0', '1000000'],
        ];

        $filePath = sys_get_temp_dir().'/test_inactive_acc_'.uniqid().'.xlsx';
        $this->xlsx->writeRows($filePath, $rows, 'Jurnal');

        $uploadedFile = new UploadedFile($filePath, 'test_inactive.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->actingAs($this->admin)->post(route('shm.accounting-import.store.journal'), [
            'file' => $uploadedFile,
        ]);
        $response->assertSessionHasErrors('file');

        @unlink($filePath);
    }

    /** 11. Test Report Duplicate for Same Period is rejected */
    public function test_report_import_for_same_period_is_rejected(): void
    {
        $rows = [
            ['Template Neraca SHM (Balance Sheet)'],
            ['Laporan Keuangan Per 2026-11-30'],
            ['ASET', '', ''],
            ['1-1100', 'Kas', '10000000.00'],
            ['TOTAL ASET', '', '10000000.00'],
        ];

        $filePath1 = sys_get_temp_dir().'/test_rep1_'.uniqid().'.xlsx';
        $this->xlsx->writeRows($filePath1, $rows, 'Neraca');
        $uploadedFile1 = new UploadedFile($filePath1, 'rep1.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->actingAs($this->admin)->post(route('shm.accounting-import.store.report'), [
            'file' => $uploadedFile1,
            'report_type' => AccountingImportBatch::REPORT_BALANCE_SHEET,
        ])->assertRedirect();

        // Second file with different name/content but same period date
        $rows2 = [
            ['Template Neraca SHM (Balance Sheet)'],
            ['Laporan Keuangan Per 2026-11-30'],
            ['ASET', '', ''],
            ['1-1100', 'Kas Bank', '12000000.00'],
            ['TOTAL ASET', '', '12000000.00'],
        ];
        $filePath2 = sys_get_temp_dir().'/test_rep2_'.uniqid().'.xlsx';
        $this->xlsx->writeRows($filePath2, $rows2, 'Neraca');
        $uploadedFile2 = new UploadedFile($filePath2, 'rep2.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $dupResp = $this->actingAs($this->admin)->post(route('shm.accounting-import.store.report'), [
            'file' => $uploadedFile2,
            'report_type' => AccountingImportBatch::REPORT_BALANCE_SHEET,
        ]);
        $dupResp->assertSessionHasErrors('file');

        @unlink($filePath1);
        @unlink($filePath2);
    }
}
