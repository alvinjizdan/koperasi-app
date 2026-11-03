<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Role;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanPayment;
use App\Models\ShmLoanProduct;
use App\Models\User;
use App\Services\Accounting\SimpleXlsxService;
use App\Support\AccountingContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShmDailyJournalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $unauthorizedUser;

    private ChartOfAccount $cashAccount;

    private ChartOfAccount $receivableAccount;

    private ChartOfAccount $interestRevenueAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        // 1. Admin user with accounting.shm.reports.view permission
        $this->admin = User::factory()->create();
        $adminRole = Role::where('name', 'admin')->first();
        if ($adminRole) {
            $this->admin->roles()->attach($adminRole->id);
        }

        // 2. Unauthorized user without accounting.shm.reports.view permission
        $this->unauthorizedUser = User::factory()->create();

        // 3. Create active SHM Chart of Accounts
        $this->cashAccount = ChartOfAccount::create([
            'accounting_context' => AccountingContext::SHM,
            'code' => '111.01',
            'name' => 'Kas Operasional SHM',
            'category' => ChartOfAccount::CATEGORY_ASSET,
            'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
            'is_active' => true,
        ]);

        $this->receivableAccount = ChartOfAccount::create([
            'accounting_context' => AccountingContext::SHM,
            'code' => '114.03',
            'name' => 'Piutang Pinjaman SHM',
            'category' => ChartOfAccount::CATEGORY_ASSET,
            'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
            'is_active' => true,
        ]);

        $this->interestRevenueAccount = ChartOfAccount::create([
            'accounting_context' => AccountingContext::SHM,
            'code' => '410.03',
            'name' => 'Pendapatan Bunga Pinjaman SHM',
            'category' => ChartOfAccount::CATEGORY_REVENUE,
            'normal_balance' => ChartOfAccount::NORMAL_CREDIT,
            'is_active' => true,
        ]);
    }

    /**
     * Test 1: Page access control (Guest -> redirect, Unauthorized -> 403, Authorized -> 200).
     */
    public function test_access_control(): void
    {
        // Guest redirected to login
        $this->get(route('shm.daily-journal.index'))
            ->assertRedirect(route('shm.login'));

        // Unauthorized user gets 403
        $this->actingAs($this->unauthorizedUser)
            ->get(route('shm.daily-journal.index'))
            ->assertForbidden();

        // Authorized user gets 200
        $this->actingAs($this->admin)
            ->get(route('shm.daily-journal.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Accounting/DailyJournal')
                ->has('entries')
                ->has('totals')
                ->has('filters')
                ->has('accounts')
            );
    }

    /**
     * Test 2: Only POSTED SHM journals are included; DRAFT and KOPERASI journals are excluded.
     */
    public function test_only_posted_shm_journals_included(): void
    {
        // 1. POSTED SHM journal
        $postedShm = JournalEntry::create([
            'accounting_context' => AccountingContext::SHM,
            'journal_number' => 'JRNL-SHM-001',
            'transaction_date' => '2026-10-15',
            'description' => 'Transaksi Jurnal SHM Sah',
            'status' => JournalEntry::STATUS_POSTED,
            'created_by' => $this->admin->id,
            'posted_by' => $this->admin->id,
            'posted_at' => now(),
        ]);
        JournalLine::create([
            'journal_entry_id' => $postedShm->id,
            'account_id' => $this->cashAccount->id,
            'description' => 'Debit Kas',
            'debit' => 1000000,
            'credit' => 0,
            'line_order' => 1,
        ]);
        JournalLine::create([
            'journal_entry_id' => $postedShm->id,
            'account_id' => $this->receivableAccount->id,
            'description' => 'Kredit Piutang',
            'debit' => 0,
            'credit' => 1000000,
            'line_order' => 2,
        ]);

        // 2. DRAFT SHM journal (should be excluded)
        $draftShm = JournalEntry::create([
            'accounting_context' => AccountingContext::SHM,
            'journal_number' => 'JRNL-SHM-DRAFT',
            'transaction_date' => '2026-10-15',
            'description' => 'Jurnal Draft SHM',
            'status' => JournalEntry::STATUS_DRAFT,
            'created_by' => $this->admin->id,
        ]);
        JournalLine::create([
            'journal_entry_id' => $draftShm->id,
            'account_id' => $this->cashAccount->id,
            'debit' => 500000,
            'credit' => 0,
            'line_order' => 1,
        ]);
        JournalLine::create([
            'journal_entry_id' => $draftShm->id,
            'account_id' => $this->receivableAccount->id,
            'debit' => 0,
            'credit' => 500000,
            'line_order' => 2,
        ]);

        // 3. POSTED Koperasi journal (should be excluded due to entity isolation)
        $koperasiAcc = ChartOfAccount::create([
            'accounting_context' => AccountingContext::KOPERASI,
            'code' => '101.01',
            'name' => 'Kas Koperasi',
            'category' => ChartOfAccount::CATEGORY_ASSET,
            'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
            'is_active' => true,
        ]);
        $postedKoperasi = JournalEntry::create([
            'accounting_context' => AccountingContext::KOPERASI,
            'journal_number' => 'JRNL-KOP-001',
            'transaction_date' => '2026-10-15',
            'description' => 'Jurnal Koperasi',
            'status' => JournalEntry::STATUS_POSTED,
            'created_by' => $this->admin->id,
            'posted_by' => $this->admin->id,
            'posted_at' => now(),
        ]);
        JournalLine::create([
            'journal_entry_id' => $postedKoperasi->id,
            'account_id' => $koperasiAcc->id,
            'debit' => 750000,
            'credit' => 0,
            'line_order' => 1,
        ]);
        JournalLine::create([
            'journal_entry_id' => $postedKoperasi->id,
            'account_id' => $koperasiAcc->id,
            'debit' => 0,
            'credit' => 750000,
            'line_order' => 2,
        ]);

        $response = $this->actingAs($this->admin)->get(route('shm.daily-journal.index'));
        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->where('totals.total_debit', fn ($v) => (float) $v === 1000000.0)
            ->where('totals.total_credit', fn ($v) => (float) $v === 1000000.0)
            ->where('totals.is_balanced', true)
            ->has('entries.data', 1)
            ->where('entries.data.0.voucher', 'JRNL-SHM-001')
        );
    }

    /**
     * Test 3: Filters (Date range, Account, and Search).
     */
    public function test_filters_and_search(): void
    {
        // Journal 1: Oct 10
        $j1 = JournalEntry::create([
            'accounting_context' => AccountingContext::SHM,
            'journal_number' => 'JRNL-20261010-001',
            'transaction_date' => '2026-10-10',
            'description' => 'Pembayaran Angsuran Nasabah A',
            'status' => JournalEntry::STATUS_POSTED,
            'created_by' => $this->admin->id,
        ]);
        JournalLine::create(['journal_entry_id' => $j1->id, 'account_id' => $this->cashAccount->id, 'debit' => 200000, 'credit' => 0, 'line_order' => 1]);
        JournalLine::create(['journal_entry_id' => $j1->id, 'account_id' => $this->receivableAccount->id, 'debit' => 0, 'credit' => 200000, 'line_order' => 2]);

        // Journal 2: Oct 20
        $j2 = JournalEntry::create([
            'accounting_context' => AccountingContext::SHM,
            'journal_number' => 'JRNL-20261020-002',
            'transaction_date' => '2026-10-20',
            'description' => 'Pendapatan Bunga Nasabah B',
            'status' => JournalEntry::STATUS_POSTED,
            'created_by' => $this->admin->id,
        ]);
        JournalLine::create(['journal_entry_id' => $j2->id, 'account_id' => $this->cashAccount->id, 'debit' => 50000, 'credit' => 0, 'line_order' => 1]);
        JournalLine::create(['journal_entry_id' => $j2->id, 'account_id' => $this->interestRevenueAccount->id, 'debit' => 0, 'credit' => 50000, 'line_order' => 2]);

        // Filter by date range Oct 15 - Oct 25 -> only Journal 2
        $resDate = $this->actingAs($this->admin)->get(route('shm.daily-journal.index', [
            'start_date' => '2026-10-15',
            'end_date' => '2026-10-25',
        ]));
        $resDate->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('entries.data', 1)
            ->where('entries.data.0.voucher', 'JRNL-20261020-002')
        );

        // Filter by account interest revenue -> only Journal 2
        $resAccount = $this->actingAs($this->admin)->get(route('shm.daily-journal.index', [
            'account_id' => $this->interestRevenueAccount->id,
        ]));
        $resAccount->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('entries.data', 1)
            ->where('entries.data.0.voucher', 'JRNL-20261020-002')
        );

        // Search by keyword 'Angsuran' -> only Journal 1
        $resSearch = $this->actingAs($this->admin)->get(route('shm.daily-journal.index', [
            'search' => 'Angsuran',
        ]));
        $resSearch->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('entries.data', 1)
            ->where('entries.data.0.voucher', 'JRNL-20261010-001')
        );
    }

    /**
     * Test 4: Customer relation resolution from source_type and source_id.
     */
    public function test_customer_relation_resolution(): void
    {
        $customer = ShmCustomer::create([
            'customer_number' => 'CUST-0099',
            'name' => 'Budi Santoso',
            'is_active' => true,
        ]);

        $product = ShmLoanProduct::create([
            'code' => 'KONSUMTIF',
            'name' => 'Pinjaman Konsumtif',
            'interest_rate_percent' => 12.0,
            'is_active' => true,
        ]);

        $application = ShmLoanApplication::create([
            'application_number' => 'APP-0099',
            'application_date' => '2026-10-01',
            'shm_customer_id' => $customer->id,
            'shm_loan_product_id' => $product->id,
            'principal' => 10000000,
            'rate_percent' => 12.0,
            'tenor_months' => 12,
            'status' => 'disbursed',
        ]);

        $loan = ShmLoan::create([
            'loan_number' => 'LOAN-0099',
            'shm_loan_application_id' => $application->id,
            'shm_customer_id' => $customer->id,
            'shm_loan_product_id' => $product->id,
            'principal' => 10000000,
            'interest_rate' => 12.0,
            'tenor_months' => 12,
            'status' => 'active',
            'disbursed_at' => now(),
        ]);

        $payment = ShmLoanPayment::create([
            'shm_loan_id' => $loan->id,
            'payment_reference' => 'PAY-REF-12345',
            'payment_date' => '2026-10-25',
            'amount' => 500000,
            'status' => ShmLoanPayment::STATUS_POSTED,
            'created_by' => $this->admin->id,
        ]);

        $entry = JournalEntry::create([
            'accounting_context' => AccountingContext::SHM,
            'journal_number' => 'JRNL-PAY-0099',
            'transaction_date' => '2026-10-25',
            'description' => 'Pembayaran Angsuran LOAN-0099',
            'status' => JournalEntry::STATUS_POSTED,
            'source_type' => ShmLoanPayment::class,
            'source_id' => $payment->id,
            'posting_type' => 'SHM_PAYMENT',
            'created_by' => $this->admin->id,
        ]);

        JournalLine::create(['journal_entry_id' => $entry->id, 'account_id' => $this->cashAccount->id, 'debit' => 500000, 'credit' => 0, 'line_order' => 1]);
        JournalLine::create(['journal_entry_id' => $entry->id, 'account_id' => $this->receivableAccount->id, 'debit' => 0, 'credit' => 500000, 'line_order' => 2]);

        $response = $this->actingAs($this->admin)->get(route('shm.daily-journal.index'));
        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->has('entries.data', 1)
            ->where('entries.data.0.customer_name', 'Budi Santoso')
            ->where('entries.data.0.member_number', 'CUST-0099')
            ->where('entries.data.0.account_number', 'LOAN-0099')
            ->where('entries.data.0.slip_number', 'PAY-REF-12345')
        );
    }

    /**
     * Test 5: Export XLSX returns valid file and contains expected structure.
     */
    public function test_export_xlsx(): void
    {
        $j1 = JournalEntry::create([
            'accounting_context' => AccountingContext::SHM,
            'journal_number' => 'JRNL-EXP-001',
            'transaction_date' => '2026-10-15',
            'description' => 'Pencairan Pinjaman SHM',
            'status' => JournalEntry::STATUS_POSTED,
            'created_by' => $this->admin->id,
        ]);
        JournalLine::create(['journal_entry_id' => $j1->id, 'account_id' => $this->receivableAccount->id, 'debit' => 5000000, 'credit' => 0, 'line_order' => 1]);
        JournalLine::create(['journal_entry_id' => $j1->id, 'account_id' => $this->cashAccount->id, 'debit' => 0, 'credit' => 5000000, 'line_order' => 2]);

        $response = $this->actingAs($this->admin)->get(route('shm.daily-journal.export', [
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-31',
        ]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $content = $response->streamedContent();
        $tempPath = sys_get_temp_dir().'/test_exported_daily_journal_'.uniqid().'.xlsx';
        file_put_contents($tempPath, $content);

        $xlsxService = app(SimpleXlsxService::class);
        $rows = $xlsxService->readRows($tempPath);
        @unlink($tempPath);

        $this->assertNotEmpty($rows);
        $this->assertSame('Jurnal Harian', $rows[0][0]);
        $this->assertStringContainsString('Periode', $rows[1][0]);
        $this->assertStringContainsString('Perkiraan', $rows[2][0]);
        $this->assertStringContainsString('Kantor', $rows[3][0]);

        // Column headers row
        $this->assertSame('No', $rows[4][0]);
        $this->assertSame('Tanggal', $rows[4][1]);
        $this->assertSame('Kode Perk.', $rows[4][2]);
        $this->assertSame('Perkiraan', $rows[4][3]);
        $this->assertSame('Voucher', $rows[4][4]);
        $this->assertSame('No Slip', $rows[4][5]);
        $this->assertSame('Keterangan', $rows[4][6]);
        $this->assertSame('Debet', $rows[4][7]);
        $this->assertSame('Kredit', $rows[4][8]);
        $this->assertSame('Nama', $rows[4][9]);
        $this->assertSame('No. Agt', $rows[4][10]);
        $this->assertSame('Rek. Agt', $rows[4][11]);
    }
}
