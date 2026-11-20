<?php

namespace Tests\Feature;

use App\Models\AccountingImportBatch;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Role;
use App\Models\ShmApplication;
use App\Models\ShmApproval;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanProduct;
use App\Models\User;
use App\Services\Accounting\FinancialReportService;
use App\Services\Accounting\SimpleXlsxService;
use App\Services\Shm\ShmLoanDisbursementService;
use App\Services\Shm\ShmRegularPaymentService;
use App\Services\Shm\ShmRepaymentScheduleService;
use App\Support\AccountingContext;
use Database\Seeders\MakroPhysicalRoleSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithShmDisbursement;
use Tests\TestCase;

class ShmManualAccountingAndImportHardeningTest extends TestCase
{
    use InteractsWithShmDisbursement;
    use RefreshDatabase;

    private User $financeUser;

    private User $unauthorizedUser;

    private ChartOfAccount $kasShm;

    private ChartOfAccount $piutangShm;

    private ChartOfAccount $pendapatanShm;

    private SimpleXlsxService $xlsx;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(MakroPhysicalRoleSeeder::class);

        // Setup SHM Accounts
        $this->kasShm = ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::SHM, 'code' => '1-1100'],
            ['name' => 'Kas Operasional SHM', 'category' => 'asset', 'normal_balance' => 'debit', 'is_active' => true]
        );
        $this->piutangShm = ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::SHM, 'code' => '1-1200'],
            ['name' => 'Piutang Pinjaman SHM', 'category' => 'asset', 'normal_balance' => 'debit', 'is_active' => true]
        );
        $this->pendapatanShm = ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::SHM, 'code' => '4-1100'],
            ['name' => 'Pendapatan Bunga SHM', 'category' => 'revenue', 'normal_balance' => 'credit', 'is_active' => true]
        );

        // Koperasi Account for cross-entity isolation check
        ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::KOPERASI, 'code' => '1-1100-KOP'],
            ['name' => 'Kas Koperasi', 'category' => 'asset', 'normal_balance' => 'debit', 'is_active' => true]
        );

        // Setup Users & Roles
        $this->financeUser = User::factory()->create(['email_verified_at' => now()]);
        $financeRole = Role::where('name', 'finance_operational_makro')->firstOrFail();
        $this->financeUser->roles()->attach($financeRole->id);

        $this->seedShmSavingsProducts();
        $this->openCashierSessionFor($this->financeUser);

        $this->unauthorizedUser = User::factory()->create(['email_verified_at' => now()]);
        $marketingRole = Role::where('name', 'marketing_makro')->firstOrFail();
        $this->unauthorizedUser->roles()->attach($marketingRole->id);

        $this->xlsx = app(SimpleXlsxService::class);
    }

    private function createApprovedLoanApplication(User $admin): ShmLoanApplication
    {
        $customer = ShmCustomer::factory()->create();
        $product = ShmLoanProduct::firstOrCreate(
            ['code' => 'PJS-TEST'],
            ['name' => 'Pinjaman Siaga SHM Test', 'category' => ShmLoanProduct::CATEGORY_PERUMAHAN, 'is_active' => true]
        );
        $product->rateTiers()->delete();
        $product->rateTiers()->create([
            'min_tenor_months' => 12,
            'max_tenor_months' => 18,
            'rate_percent' => 1.30,
            'rate_period' => 'monthly',
            'calculation_method' => 'flat',
        ]);

        $mirror = ShmApplication::create([
            'application_number' => 'APP-MIRROR-'.Str::upper(Str::random(6)),
            'shm_customer_id' => $customer->id,
            'marketing_id' => $admin->id,
            'status' => 'approved',
        ]);

        ShmApproval::create([
            'shm_application_id' => $mirror->id,
            'decided_by' => $admin->id,
            'decided_at' => now()->subMinute(),
            'status' => ShmApproval::STATUS_APPROVED_BM,
            'notes' => 'Disetujui Level 1 BM',
        ]);

        ShmApproval::create([
            'shm_application_id' => $mirror->id,
            'decided_by' => $admin->id,
            'decided_at' => now(),
            'status' => ShmApproval::STATUS_APPROVED,
            'notes' => 'Disetujui Level 2 Dirut',
        ]);

        return ShmLoanApplication::create([
            'application_number' => 'PENGAJ-'.Str::upper(Str::random(8)),
            'shm_customer_id' => $customer->id,
            'shm_loan_product_id' => $product->id,
            'shm_application_id' => $mirror->id,
            'application_date' => now()->toDateString(),
            'principal' => 50000000,
            'approved_amount' => 50000000,
            'tenor_months' => 12,
            'first_due_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'marketing_id' => $admin->id,
            'status' => 'approved',
        ]);
    }

    /**
     * 1. Operational Guard Test:
     * Confirm that disbursement and regular repayment DO NOT create automatic journals
     * in SHM context (Option A enforcement).
     */
    public function test_operational_services_do_not_create_automatic_accounting_journals(): void
    {
        $admin = $this->financeUser;
        $initialCount = JournalEntry::where('accounting_context', AccountingContext::SHM)->count();

        // A. Disbursement
        $app = $this->createApprovedLoanApplication($admin);
        $disbService = app(ShmLoanDisbursementService::class);
        $loan = $disbService->disburse($app, $admin->id);
        $this->assertInstanceOf(ShmLoan::class, $loan);
        $this->assertSame($initialCount, JournalEntry::where('accounting_context', AccountingContext::SHM)->count());

        // B. Repayment
        $schedService = app(ShmRepaymentScheduleService::class);
        $schedService->generate($loan, $admin->id);
        $loan->refresh();
        $installment = $loan->installments()->first();

        $payService = app(ShmRegularPaymentService::class);
        $payResult = $payService->pay($loan, [
            'payment_date' => now()->toDateString(),
            'amount' => $installment->total_amount,
            'payment_method' => 'cash',
        ], $admin->id);
        $this->assertNotNull($payResult);
        $this->assertSame($initialCount, JournalEntry::where('accounting_context', AccountingContext::SHM)->count());
    }

    /**
     * 2. Scope A: Validation for Missing Headers or Empty XLSX
     */
    public function test_import_rejects_empty_file_or_invalid_headers(): void
    {
        $filePath = sys_get_temp_dir().'/empty_'.uniqid().'.xlsx';
        $this->xlsx->writeRows($filePath, [], 'Empty');
        $uploaded = new UploadedFile($filePath, 'empty.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->actingAs($this->financeUser)->post(route('shm.accounting-import.store.journal'), [
            'file' => $uploaded,
        ]);
        $response->assertSessionHasErrors('file');
        @unlink($filePath);

        // Invalid headers
        $filePath2 = sys_get_temp_dir().'/bad_header_'.uniqid().'.xlsx';
        $this->xlsx->writeRows($filePath2, [
            ['Random Column A', 'Random Column B'],
            ['Val 1', 'Val 2'],
        ], 'BadHeader');
        $uploaded2 = new UploadedFile($filePath2, 'bad_header.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response2 = $this->actingAs($this->financeUser)->post(route('shm.accounting-import.store.journal'), [
            'file' => $uploaded2,
        ]);
        $response2->assertSessionHasErrors('file');
        @unlink($filePath2);
    }

    /**
     * 3. Scope A: Validation for Non-Existent or Cross-Entity (Koperasi) Account Codes
     */
    public function test_import_rejects_cross_entity_koperasi_account_codes(): void
    {
        $rows = [
            ['Tanggal', 'No Bukti', 'Kode Akun', 'Keterangan', 'Debit', 'Kredit'],
            [now()->toDateString(), 'JU-KOP-TEST', '1-1100-KOP', 'Kas Koperasi dimasukkan ke SHM', '1000000', '0'],
            [now()->toDateString(), 'JU-KOP-TEST', '4-1100', 'Pendapatan SHM', '0', '1000000'],
        ];

        $filePath = sys_get_temp_dir().'/koperasi_acc_'.uniqid().'.xlsx';
        $this->xlsx->writeRows($filePath, $rows, 'Jurnal');
        $uploaded = new UploadedFile($filePath, 'koperasi_acc.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->actingAs($this->financeUser)->post(route('shm.accounting-import.store.journal'), [
            'file' => $uploaded,
        ]);
        $response->assertSessionHasErrors('file');
        @unlink($filePath);
    }

    /**
     * 4. Scope A: Validation for Unbalanced Journal Entry (Debit != Credit)
     */
    public function test_import_rejects_unbalanced_journal(): void
    {
        $rows = [
            ['Tanggal', 'No Bukti', 'Kode Akun', 'Keterangan', 'Debit', 'Kredit'],
            [now()->toDateString(), 'JU-UNBALANCED', '1-1100', 'Debit 500rb', '500000', '0'],
            [now()->toDateString(), 'JU-UNBALANCED', '4-1100', 'Kredit 400rb', '0', '400000'],
        ];

        $filePath = sys_get_temp_dir().'/unbalanced_'.uniqid().'.xlsx';
        $this->xlsx->writeRows($filePath, $rows, 'Jurnal');
        $uploaded = new UploadedFile($filePath, 'unbalanced.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->actingAs($this->financeUser)->post(route('shm.accounting-import.store.journal'), [
            'file' => $uploaded,
        ]);
        $response->assertSessionHasErrors('file');
        @unlink($filePath);
    }

    /**
     * 5. Scope B: Duplicate Prevention via Checksum and Secondary Journal Ref
     */
    public function test_import_prevents_exact_checksum_and_semantic_duplicate_imports(): void
    {
        $rows = [
            ['Tanggal', 'No Bukti', 'Kode Akun', 'Keterangan', 'Debit', 'Kredit'],
            [now()->toDateString(), 'JU-DUP-001', '1-1100', 'Kas Masuk', '1500000', '0'],
            [now()->toDateString(), 'JU-DUP-001', '4-1100', 'Pendapatan Bunga', '0', '1500000'],
        ];

        $filePath = sys_get_temp_dir().'/first_import_'.uniqid().'.xlsx';
        $this->xlsx->writeRows($filePath, $rows, 'Jurnal');
        $uploaded = new UploadedFile($filePath, 'first_import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        // 1st import succeeds
        $res1 = $this->actingAs($this->financeUser)->post(route('shm.accounting-import.store.journal'), [
            'file' => $uploaded,
        ]);
        $res1->assertRedirect(route('shm.accounting-import.index'));

        $batch = AccountingImportBatch::latest('id')->firstOrFail();
        $this->assertSame(1, $batch->success_count);

        // 2nd import with exact same file -> blocked by checksum
        $uploadedDuplicate = new UploadedFile($filePath, 'first_import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $res2 = $this->actingAs($this->financeUser)->post(route('shm.accounting-import.store.journal'), [
            'file' => $uploadedDuplicate,
        ]);
        $res2->assertSessionHasErrors('file');

        // 3rd import with different checksum but same Reference number -> blocked by semantic ref check
        $rows2 = [
            ['Tanggal', 'No Bukti', 'Kode Akun', 'Keterangan', 'Debit', 'Kredit'],
            [now()->toDateString(), 'JU-DUP-001', '1-1100', 'Kas Masuk Berbeda Sedikit', '1500000', '0'],
            [now()->toDateString(), 'JU-DUP-001', '4-1100', 'Pendapatan Bunga', '0', '1500000'],
        ];
        $filePath2 = sys_get_temp_dir().'/second_import_'.uniqid().'.xlsx';
        $this->xlsx->writeRows($filePath2, $rows2, 'Jurnal2');
        $uploadedDiffChecksum = new UploadedFile($filePath2, 'diff_checksum.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $res3 = $this->actingAs($this->financeUser)->post(route('shm.accounting-import.store.journal'), [
            'file' => $uploadedDiffChecksum,
        ]);
        $res3->assertSessionHasErrors('file');

        @unlink($filePath);
        @unlink($filePath2);
    }

    /**
     * 6. Scope C, D, F: Imported Journals are created as DRAFT,
     * DO NOT affect Balance Sheet until manually posted, and can be posted with permission.
     */
    public function test_imported_draft_journals_do_not_affect_balance_sheet_until_posted(): void
    {
        $reportService = app(FinancialReportService::class);
        $asOfDate = now()->toDateString();

        $initialBalanceSheet = $reportService->balanceSheet($asOfDate, AccountingContext::SHM);
        $initialTotalAssets = $initialBalanceSheet['summary']['total_assets'] ?? 0;

        $importAmount = 2500000.0;
        $rows = [
            ['Tanggal', 'No Bukti', 'Kode Akun', 'Keterangan', 'Debit', 'Kredit'],
            [$asOfDate, 'JU-POST-TEST', '1-1100', 'Setoran Kas SHM', (string) $importAmount, '0'],
            [$asOfDate, 'JU-POST-TEST', '4-1100', 'Pendapatan SHM', '0', (string) $importAmount],
        ];

        $filePath = sys_get_temp_dir().'/post_test_'.uniqid().'.xlsx';
        $this->xlsx->writeRows($filePath, $rows, 'Jurnal');
        $uploaded = new UploadedFile($filePath, 'post_test.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->actingAs($this->financeUser)->post(route('shm.accounting-import.store.journal'), [
            'file' => $uploaded,
        ])->assertRedirect();

        $batch = AccountingImportBatch::latest('id')->firstOrFail();
        $journal = JournalEntry::where('import_batch_id', $batch->id)->firstOrFail();

        // 1. Verify Draft Status & Batch Linkage
        $this->assertSame(JournalEntry::STATUS_DRAFT, $journal->status);
        $this->assertSame($batch->id, $journal->import_batch_id);
        $this->assertSame('import_xlsx', $journal->posting_type);

        // 2. Drafts MUST NOT affect Balance Sheet
        $midBalanceSheet = $reportService->balanceSheet($asOfDate, AccountingContext::SHM);
        $midTotalAssets = $midBalanceSheet['summary']['total_assets'] ?? 0;
        $this->assertEquals($initialTotalAssets, $midTotalAssets, 'Draft journal must not affect Balance Sheet.');

        // 3. Unauthorized User cannot post journal
        $unauthRes = $this->actingAs($this->unauthorizedUser)->post(route('shm.accounting-import.post.journal', $journal));
        $unauthRes->assertForbidden();
        $journal->refresh();
        $this->assertSame(JournalEntry::STATUS_DRAFT, $journal->status);

        // 4. Authorized Finance User posts journal
        $authRes = $this->actingAs($this->financeUser)->post(route('shm.accounting-import.post.journal', $journal));
        $authRes->assertRedirect();
        $journal->refresh();
        $this->assertSame(JournalEntry::STATUS_POSTED, $journal->status);
        $this->assertSame($this->financeUser->id, $journal->posted_by);

        // 5. Posted Journal IS now reflected in Balance Sheet
        $postBalanceSheet = $reportService->balanceSheet($asOfDate, AccountingContext::SHM);
        $postTotalAssets = $postBalanceSheet['summary']['total_assets'] ?? 0;
        $this->assertEquals($initialTotalAssets + $importAmount, $postTotalAssets, 'Posted journal must be reflected in Balance Sheet.');

        @unlink($filePath);
    }

    /**
     * 7. Scope D: Posting is Idempotent / Cannot double-post already POSTED journal
     */
    public function test_cannot_post_already_posted_journal(): void
    {
        $rows = [
            ['Tanggal', 'No Bukti', 'Kode Akun', 'Keterangan', 'Debit', 'Kredit'],
            [now()->toDateString(), 'JU-DOUBLE-POST', '1-1100', 'Kas', '1000000', '0'],
            [now()->toDateString(), 'JU-DOUBLE-POST', '4-1100', 'Pendapatan', '0', '1000000'],
        ];

        $filePath = sys_get_temp_dir().'/double_post_'.uniqid().'.xlsx';
        $this->xlsx->writeRows($filePath, $rows, 'Jurnal');
        $uploaded = new UploadedFile($filePath, 'double_post.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->actingAs($this->financeUser)->post(route('shm.accounting-import.store.journal'), [
            'file' => $uploaded,
        ])->assertRedirect();

        $batch = AccountingImportBatch::latest('id')->firstOrFail();
        $journal = JournalEntry::where('import_batch_id', $batch->id)->firstOrFail();

        // 1st Post
        $this->actingAs($this->financeUser)->post(route('shm.accounting-import.post.journal', $journal))->assertRedirect();
        $journal->refresh();
        $this->assertSame(JournalEntry::STATUS_POSTED, $journal->status);

        // 2nd Post attempt should fail with validation exception
        $res = $this->actingAs($this->financeUser)->post(route('shm.accounting-import.post.journal', $journal));
        $res->assertSessionHasErrors('status');

        @unlink($filePath);
    }
}
