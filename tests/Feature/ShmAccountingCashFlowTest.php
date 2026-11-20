<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Role;
use App\Models\ShmCashTransaction;
use App\Models\User;
use App\Services\Accounting\FinancialReportService;
use App\Services\Accounting\ShmCashFlowService;
use App\Support\AccountingContext;
use Database\Seeders\MakroPhysicalRoleSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ShmAccountingCashFlowTest extends TestCase
{
    use RefreshDatabase;

    protected User $financeUser;

    protected User $readOnlyUser;

    protected User $unauthorizedUser;

    protected ChartOfAccount $kasShm;

    protected ChartOfAccount $bankShm;

    protected ChartOfAccount $pendapatanShm;

    protected ChartOfAccount $bebanOperasionalShm;

    protected ChartOfAccount $hutangShm;

    protected ChartOfAccount $coopKas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(MakroPhysicalRoleSeeder::class);

        // Physical role finance_operational_makro has:
        // accounting.shm.view, accounting.shm.journals.view, accounting.shm.journals.create,
        // accounting.shm.journals.post, accounting.shm.journals.reverse, accounting.shm.reports.view
        $this->financeUser = User::factory()->create();
        $financeRole = Role::where('name', 'finance_operational_makro')->first();
        if ($financeRole) {
            $this->financeUser->roles()->attach($financeRole->id);
        }

        // Read-only user (only accounting.shm.journals.view)
        $this->readOnlyUser = User::factory()->create();
        $readRole = Role::where('name', 'direktur_utama_makro')->first();
        if ($readRole) {
            $this->readOnlyUser->roles()->attach($readRole->id);
        }

        // Unauthorized user without any SHM permissions
        $this->unauthorizedUser = User::factory()->create();

        // Create SHM Chart of Accounts
        $this->kasShm = ChartOfAccount::create([
            'accounting_context' => AccountingContext::SHM,
            'code' => '1-1100',
            'name' => 'Kas Operasional SHM',
            'category' => 'asset',
            'normal_balance' => 'debit',
            'is_active' => true,
        ]);

        $this->bankShm = ChartOfAccount::create([
            'accounting_context' => AccountingContext::SHM,
            'code' => '1-1200',
            'name' => 'Bank BCA SHM',
            'category' => 'asset',
            'normal_balance' => 'debit',
            'is_active' => true,
        ]);

        $this->pendapatanShm = ChartOfAccount::create([
            'accounting_context' => AccountingContext::SHM,
            'code' => '4-1100',
            'name' => 'Pendapatan Lain-lain SHM',
            'category' => 'revenue',
            'normal_balance' => 'credit',
            'is_active' => true,
        ]);

        $this->bebanOperasionalShm = ChartOfAccount::create([
            'accounting_context' => AccountingContext::SHM,
            'code' => '5-1100',
            'name' => 'Beban Operasional Kantor SHM',
            'category' => 'expense',
            'normal_balance' => 'debit',
            'is_active' => true,
        ]);

        $this->hutangShm = ChartOfAccount::create([
            'accounting_context' => AccountingContext::SHM,
            'code' => '2-1100',
            'name' => 'Hutang Usaha SHM',
            'category' => 'liability',
            'normal_balance' => 'credit',
            'is_active' => true,
        ]);

        // Create Cooperative Chart of Account (Strict Isolation Check)
        $this->coopKas = ChartOfAccount::create([
            'accounting_context' => AccountingContext::KOPERASI,
            'code' => '1-1001',
            'name' => 'Kas Koperasi',
            'category' => 'asset',
            'normal_balance' => 'debit',
            'is_active' => true,
        ]);
    }

    /* -------------------------------------------------------------------------- */
    /* Scope 1 & 2: Arus Kas Masuk (Cash Inflow) CRUD & Validation */
    /* -------------------------------------------------------------------------- */

    public function test_authorized_user_can_view_cash_inflow_index(): void
    {
        $response = $this->actingAs($this->financeUser)
            ->get(route('shm.cash-inflow.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Shm/Accounting/CashFlow/InflowIndex')
            ->has('transactions')
            ->has('cashAccounts')
            ->has('counterpartAccounts')
        );
    }

    public function test_can_create_cash_inflow_draft(): void
    {
        $payload = [
            'type' => ShmCashTransaction::TYPE_INFLOW,
            'transaction_date' => '2026-11-12',
            'cash_account_id' => $this->kasShm->id,
            'account_id' => $this->pendapatanShm->id,
            'amount' => 5000000,
            'description' => 'Penerimaan dana kas masuk',
        ];

        $response = $this->actingAs($this->financeUser)
            ->post(route('shm.cash-inflow.store'), $payload);

        $response->assertRedirect(route('shm.cash-inflow.index'));
        $this->assertDatabaseHas('shm_cash_transactions', [
            'type' => ShmCashTransaction::TYPE_INFLOW,
            'status' => ShmCashTransaction::STATUS_DRAFT,
            'amount' => 5000000.00,
            'cash_account_id' => $this->kasShm->id,
            'account_id' => $this->pendapatanShm->id,
            'journal_entry_id' => null,
        ]);
    }

    public function test_cash_inflow_creation_generates_unique_transaction_number(): void
    {
        $payload = [
            'type' => ShmCashTransaction::TYPE_INFLOW,
            'transaction_date' => '2026-11-12',
            'cash_account_id' => $this->kasShm->id,
            'account_id' => $this->pendapatanShm->id,
            'amount' => 1000000,
        ];

        $this->actingAs($this->financeUser)->post(route('shm.cash-inflow.store'), $payload);
        $this->actingAs($this->financeUser)->post(route('shm.cash-inflow.store'), $payload);

        $tx1 = ShmCashTransaction::where('transaction_number', 'KM-SHM-20261112-0001')->first();
        $tx2 = ShmCashTransaction::where('transaction_number', 'KM-SHM-20261112-0002')->first();

        $this->assertNotNull($tx1);
        $this->assertNotNull($tx2);
        $this->assertNotEquals($tx1->transaction_number, $tx2->transaction_number);
    }

    public function test_can_update_cash_inflow_draft(): void
    {
        $tx = ShmCashTransaction::create([
            'transaction_number' => 'KM-SHM-20261112-9999',
            'type' => ShmCashTransaction::TYPE_INFLOW,
            'transaction_date' => '2026-11-12',
            'cash_account_id' => $this->kasShm->id,
            'account_id' => $this->pendapatanShm->id,
            'amount' => 1000000,
            'status' => ShmCashTransaction::STATUS_DRAFT,
            'created_by' => $this->financeUser->id,
        ]);

        $response = $this->actingAs($this->financeUser)
            ->put(route('shm.cash-inflow.update', $tx->id), [
                'amount' => 2500000,
                'description' => 'Updated amount',
                'cash_account_id' => $this->bankShm->id,
                'account_id' => $this->pendapatanShm->id,
                'transaction_date' => '2026-11-12',
            ]);

        $response->assertRedirect(route('shm.cash-inflow.index'));
        $this->assertDatabaseHas('shm_cash_transactions', [
            'id' => $tx->id,
            'amount' => 2500000.00,
            'cash_account_id' => $this->bankShm->id,
            'description' => 'Updated amount',
        ]);
    }

    public function test_can_delete_cash_inflow_draft(): void
    {
        $tx = ShmCashTransaction::create([
            'transaction_number' => 'KM-SHM-20261112-8888',
            'type' => ShmCashTransaction::TYPE_INFLOW,
            'transaction_date' => '2026-11-12',
            'cash_account_id' => $this->kasShm->id,
            'account_id' => $this->pendapatanShm->id,
            'amount' => 1000000,
            'status' => ShmCashTransaction::STATUS_DRAFT,
            'created_by' => $this->financeUser->id,
        ]);

        $response = $this->actingAs($this->financeUser)
            ->delete(route('shm.cash-inflow.destroy', $tx->id));

        $response->assertRedirect(route('shm.cash-inflow.index'));
        $this->assertDatabaseMissing('shm_cash_transactions', ['id' => $tx->id]);
    }

    /* -------------------------------------------------------------------------- */
    /* Scope 3 & 4: Arus Kas Keluar (Cash Outflow) CRUD & Validation */
    /* -------------------------------------------------------------------------- */

    public function test_authorized_user_can_view_cash_outflow_index(): void
    {
        $response = $this->actingAs($this->financeUser)
            ->get(route('shm.cash-outflow.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Shm/Accounting/CashFlow/OutflowIndex')
            ->has('transactions')
            ->has('cashAccounts')
            ->has('counterpartAccounts')
        );
    }

    public function test_can_create_cash_outflow_draft(): void
    {
        $payload = [
            'type' => ShmCashTransaction::TYPE_OUTFLOW,
            'transaction_date' => '2026-11-12',
            'cash_account_id' => $this->kasShm->id,
            'account_id' => $this->bebanOperasionalShm->id,
            'amount' => 750000,
            'description' => 'Beli ATK Kantor',
        ];

        $response = $this->actingAs($this->financeUser)
            ->post(route('shm.cash-outflow.store'), $payload);

        $response->assertRedirect(route('shm.cash-outflow.index'));
        $this->assertDatabaseHas('shm_cash_transactions', [
            'type' => ShmCashTransaction::TYPE_OUTFLOW,
            'status' => ShmCashTransaction::STATUS_DRAFT,
            'amount' => 750000.00,
            'cash_account_id' => $this->kasShm->id,
            'account_id' => $this->bebanOperasionalShm->id,
            'journal_entry_id' => null,
        ]);
    }

    public function test_cash_outflow_creation_generates_unique_transaction_number(): void
    {
        $payload = [
            'type' => ShmCashTransaction::TYPE_OUTFLOW,
            'transaction_date' => '2026-11-12',
            'cash_account_id' => $this->kasShm->id,
            'account_id' => $this->bebanOperasionalShm->id,
            'amount' => 500000,
        ];

        $this->actingAs($this->financeUser)->post(route('shm.cash-outflow.store'), $payload);
        $this->actingAs($this->financeUser)->post(route('shm.cash-outflow.store'), $payload);

        $tx1 = ShmCashTransaction::where('transaction_number', 'KK-SHM-20261112-0001')->first();
        $tx2 = ShmCashTransaction::where('transaction_number', 'KK-SHM-20261112-0002')->first();

        $this->assertNotNull($tx1);
        $this->assertNotNull($tx2);
        $this->assertNotEquals($tx1->transaction_number, $tx2->transaction_number);
    }

    public function test_can_update_cash_outflow_draft(): void
    {
        $tx = ShmCashTransaction::create([
            'transaction_number' => 'KK-SHM-20261112-9999',
            'type' => ShmCashTransaction::TYPE_OUTFLOW,
            'transaction_date' => '2026-11-12',
            'cash_account_id' => $this->kasShm->id,
            'account_id' => $this->bebanOperasionalShm->id,
            'amount' => 300000,
            'status' => ShmCashTransaction::STATUS_DRAFT,
            'created_by' => $this->financeUser->id,
        ]);

        $response = $this->actingAs($this->financeUser)
            ->put(route('shm.cash-outflow.update', $tx->id), [
                'amount' => 450000,
                'description' => 'Updated biaya ATK',
                'cash_account_id' => $this->kasShm->id,
                'account_id' => $this->bebanOperasionalShm->id,
                'transaction_date' => '2026-11-12',
            ]);

        $response->assertRedirect(route('shm.cash-outflow.index'));
        $this->assertDatabaseHas('shm_cash_transactions', [
            'id' => $tx->id,
            'amount' => 450000.00,
            'description' => 'Updated biaya ATK',
        ]);
    }

    public function test_can_delete_cash_outflow_draft(): void
    {
        $tx = ShmCashTransaction::create([
            'transaction_number' => 'KK-SHM-20261112-7777',
            'type' => ShmCashTransaction::TYPE_OUTFLOW,
            'transaction_date' => '2026-11-12',
            'cash_account_id' => $this->kasShm->id,
            'account_id' => $this->bebanOperasionalShm->id,
            'amount' => 100000,
            'status' => ShmCashTransaction::STATUS_DRAFT,
            'created_by' => $this->financeUser->id,
        ]);

        $response = $this->actingAs($this->financeUser)
            ->delete(route('shm.cash-outflow.destroy', $tx->id));

        $response->assertRedirect(route('shm.cash-outflow.index'));
        $this->assertDatabaseMissing('shm_cash_transactions', ['id' => $tx->id]);
    }

    /* -------------------------------------------------------------------------- */
    /* Scope 5 & 6: Accounting Integration & Double Entry Validation */
    /* -------------------------------------------------------------------------- */

    public function test_posting_cash_inflow_creates_balanced_double_entry_journal(): void
    {
        $tx = ShmCashTransaction::create([
            'transaction_number' => 'KM-SHM-20261112-0100',
            'type' => ShmCashTransaction::TYPE_INFLOW,
            'transaction_date' => '2026-11-12',
            'cash_account_id' => $this->kasShm->id,
            'account_id' => $this->pendapatanShm->id,
            'amount' => 10000000,
            'description' => 'Penerimaan Pendapatan',
            'status' => ShmCashTransaction::STATUS_DRAFT,
            'created_by' => $this->financeUser->id,
        ]);

        $response = $this->actingAs($this->financeUser)
            ->post(route('shm.cash-inflow.post', $tx->id));

        $response->assertRedirect(route('shm.cash-inflow.index'));

        $tx->refresh();
        $this->assertEquals(ShmCashTransaction::STATUS_POSTED, $tx->status);
        $this->assertNotNull($tx->journal_entry_id);
        $this->assertNotNull($tx->posted_at);
        $this->assertEquals($this->financeUser->id, $tx->posted_by);

        // Check Journal Entry
        $journal = JournalEntry::with('lines')->find($tx->journal_entry_id);
        $this->assertNotNull($journal);
        $this->assertEquals(AccountingContext::SHM, $journal->accounting_context);
        $this->assertEquals(JournalEntry::STATUS_POSTED, $journal->status);
        $this->assertEquals(10000000, $journal->totalDebit());
        $this->assertEquals(10000000, $journal->totalCredit());

        // Inflow: Debit Kas, Credit Pendapatan
        $debitLine = $journal->lines->where('account_id', $this->kasShm->id)->first();
        $creditLine = $journal->lines->where('account_id', $this->pendapatanShm->id)->first();

        $this->assertEquals(10000000, (float) $debitLine->debit);
        $this->assertEquals(0, (float) $debitLine->credit);
        $this->assertEquals(0, (float) $creditLine->debit);
        $this->assertEquals(10000000, (float) $creditLine->credit);
    }

    public function test_posting_cash_outflow_creates_balanced_double_entry_journal(): void
    {
        $tx = ShmCashTransaction::create([
            'transaction_number' => 'KK-SHM-20261112-0200',
            'type' => ShmCashTransaction::TYPE_OUTFLOW,
            'transaction_date' => '2026-11-12',
            'cash_account_id' => $this->kasShm->id,
            'account_id' => $this->bebanOperasionalShm->id,
            'amount' => 2000000,
            'description' => 'Bayar Sewa Kantor',
            'status' => ShmCashTransaction::STATUS_DRAFT,
            'created_by' => $this->financeUser->id,
        ]);

        $response = $this->actingAs($this->financeUser)
            ->post(route('shm.cash-outflow.post', $tx->id));

        $response->assertRedirect(route('shm.cash-outflow.index'));

        $tx->refresh();
        $this->assertEquals(ShmCashTransaction::STATUS_POSTED, $tx->status);
        $this->assertNotNull($tx->journal_entry_id);

        // Check Journal Entry
        $journal = JournalEntry::with('lines')->find($tx->journal_entry_id);
        $this->assertNotNull($journal);
        $this->assertEquals(AccountingContext::SHM, $journal->accounting_context);
        $this->assertEquals(JournalEntry::STATUS_POSTED, $journal->status);
        $this->assertEquals(2000000, $journal->totalDebit());
        $this->assertEquals(2000000, $journal->totalCredit());

        // Outflow: Debit Beban, Credit Kas
        $debitLine = $journal->lines->where('account_id', $this->bebanOperasionalShm->id)->first();
        $creditLine = $journal->lines->where('account_id', $this->kasShm->id)->first();

        $this->assertEquals(2000000, (float) $debitLine->debit);
        $this->assertEquals(0, (float) $debitLine->credit);
        $this->assertEquals(0, (float) $creditLine->debit);
        $this->assertEquals(2000000, (float) $creditLine->credit);
    }

    public function test_cannot_post_transaction_twice(): void
    {
        $tx = ShmCashTransaction::create([
            'transaction_number' => 'KM-SHM-20261112-0300',
            'type' => ShmCashTransaction::TYPE_INFLOW,
            'transaction_date' => '2026-11-12',
            'cash_account_id' => $this->kasShm->id,
            'account_id' => $this->pendapatanShm->id,
            'amount' => 500000,
            'status' => ShmCashTransaction::STATUS_DRAFT,
            'created_by' => $this->financeUser->id,
        ]);

        $this->actingAs($this->financeUser)->post(route('shm.cash-inflow.post', $tx->id));

        // Attempt second post
        $response = $this->actingAs($this->financeUser)->post(route('shm.cash-inflow.post', $tx->id));
        $response->assertSessionHasErrors(['status']);
    }

    public function test_cannot_update_or_delete_posted_transaction(): void
    {
        $tx = ShmCashTransaction::create([
            'transaction_number' => 'KM-SHM-20261112-0400',
            'type' => ShmCashTransaction::TYPE_INFLOW,
            'transaction_date' => '2026-11-12',
            'cash_account_id' => $this->kasShm->id,
            'account_id' => $this->pendapatanShm->id,
            'amount' => 500000,
            'status' => ShmCashTransaction::STATUS_DRAFT,
            'created_by' => $this->financeUser->id,
        ]);

        $this->actingAs($this->financeUser)->post(route('shm.cash-inflow.post', $tx->id));

        // Attempt edit
        $updateResp = $this->actingAs($this->financeUser)
            ->put(route('shm.cash-inflow.update', $tx->id), [
                'amount' => 700000,
                'cash_account_id' => $this->kasShm->id,
                'account_id' => $this->pendapatanShm->id,
            ]);
        $updateResp->assertSessionHasErrors(['status']);

        // Attempt delete
        $deleteResp = $this->actingAs($this->financeUser)
            ->delete(route('shm.cash-inflow.destroy', $tx->id));
        $deleteResp->assertSessionHasErrors(['status']);
    }

    public function test_can_reverse_posted_transaction_with_neutralizing_journal(): void
    {
        $tx = ShmCashTransaction::create([
            'transaction_number' => 'KM-SHM-20261112-0500',
            'type' => ShmCashTransaction::TYPE_INFLOW,
            'transaction_date' => '2026-11-12',
            'cash_account_id' => $this->kasShm->id,
            'account_id' => $this->pendapatanShm->id,
            'amount' => 3000000,
            'status' => ShmCashTransaction::STATUS_DRAFT,
            'created_by' => $this->financeUser->id,
        ]);

        $this->actingAs($this->financeUser)->post(route('shm.cash-inflow.post', $tx->id));

        $response = $this->actingAs($this->financeUser)
            ->post(route('shm.cash-inflow.reverse', $tx->id), [
                'reason' => 'Salah catat nominal',
            ]);

        $response->assertRedirect(route('shm.cash-inflow.index'));

        $tx->refresh();
        $this->assertEquals(ShmCashTransaction::STATUS_REVERSED, $tx->status);
        $this->assertEquals('Salah catat nominal', $tx->reversal_reason);
        $this->assertNotNull($tx->reversed_at);
        $this->assertEquals($this->financeUser->id, $tx->reversed_by);

        $originalJournal = JournalEntry::find($tx->journal_entry_id);
        $this->assertEquals(JournalEntry::STATUS_REVERSED, $originalJournal->status);
        $this->assertNotNull($originalJournal->reversed_by_entry_id);

        $reversalJournal = JournalEntry::find($originalJournal->reversed_by_entry_id);
        $this->assertEquals(JournalEntry::STATUS_POSTED, $reversalJournal->status);
    }

    /* -------------------------------------------------------------------------- */
    /* Scope 7: Balance Sheet Integration (Core Requirement) */
    /* -------------------------------------------------------------------------- */

    public function test_draft_transactions_do_not_affect_balance_sheet(): void
    {
        $reportService = app(FinancialReportService::class);
        $beforeReport = $reportService->balanceSheet('2026-11-12', AccountingContext::SHM);

        // Create draft inflow & outflow
        ShmCashTransaction::create([
            'transaction_number' => 'KM-SHM-20261112-DRAFT1',
            'type' => ShmCashTransaction::TYPE_INFLOW,
            'transaction_date' => '2026-11-12',
            'cash_account_id' => $this->kasShm->id,
            'account_id' => $this->pendapatanShm->id,
            'amount' => 50000000,
            'status' => ShmCashTransaction::STATUS_DRAFT,
            'created_by' => $this->financeUser->id,
        ]);

        $afterReport = $reportService->balanceSheet('2026-11-12', AccountingContext::SHM);

        $this->assertEquals($beforeReport['summary']['total_assets'], $afterReport['summary']['total_assets']);
        $this->assertEquals($beforeReport['summary']['total_liabilities'], $afterReport['summary']['total_liabilities']);
        $this->assertEquals($beforeReport['summary']['total_equity'], $afterReport['summary']['total_equity']);
    }

    public function test_posted_cash_inflow_correctly_increases_balance_sheet_cash_and_is_balanced(): void
    {
        $reportService = app(FinancialReportService::class);
        $initialReport = $reportService->balanceSheet('2026-11-12', AccountingContext::SHM);

        $tx = ShmCashTransaction::create([
            'transaction_number' => 'KM-SHM-20261112-BS1',
            'type' => ShmCashTransaction::TYPE_INFLOW,
            'transaction_date' => '2026-11-12',
            'cash_account_id' => $this->kasShm->id,
            'account_id' => $this->hutangShm->id, // Asset vs Liability -> directly in Balance Sheet
            'amount' => 15000000,
            'status' => ShmCashTransaction::STATUS_DRAFT,
            'created_by' => $this->financeUser->id,
        ]);

        $this->actingAs($this->financeUser)->post(route('shm.cash-inflow.post', $tx->id));

        $newReport = $reportService->balanceSheet('2026-11-12', AccountingContext::SHM);

        // Assets must increase by 15,000,000
        $this->assertEquals($initialReport['summary']['total_assets'] + 15000000, $newReport['summary']['total_assets']);
        // Liabilities must increase by 15,000,000
        $this->assertEquals($initialReport['summary']['total_liabilities'] + 15000000, $newReport['summary']['total_liabilities']);
        $this->assertEquals($initialReport['summary']['total_liabilities_and_equity'] + 15000000, $newReport['summary']['total_liabilities_and_equity']);
        // Perfectly balanced
        $this->assertTrue($newReport['balanced']);
        $this->assertEquals($newReport['summary']['total_assets'], $newReport['summary']['total_liabilities_and_equity']);
    }

    public function test_posted_cash_outflow_correctly_decreases_balance_sheet_cash_and_is_balanced(): void
    {
        $reportService = app(FinancialReportService::class);

        // First deposit some cash via liability so balance sheet is balanced
        $inflowTx = ShmCashTransaction::create([
            'transaction_number' => 'KM-SHM-20261112-BS2',
            'type' => ShmCashTransaction::TYPE_INFLOW,
            'transaction_date' => '2026-11-12',
            'cash_account_id' => $this->kasShm->id,
            'account_id' => $this->hutangShm->id,
            'amount' => 10000000,
            'status' => ShmCashTransaction::STATUS_DRAFT,
            'created_by' => $this->financeUser->id,
        ]);
        $this->actingAs($this->financeUser)->post(route('shm.cash-inflow.post', $inflowTx->id));

        $midReport = $reportService->balanceSheet('2026-11-12', AccountingContext::SHM);

        // Post cash outflow 4,000,000 to pay off liability
        $outflowTx = ShmCashTransaction::create([
            'transaction_number' => 'KK-SHM-20261112-BS3',
            'type' => ShmCashTransaction::TYPE_OUTFLOW,
            'transaction_date' => '2026-11-12',
            'cash_account_id' => $this->kasShm->id,
            'account_id' => $this->hutangShm->id,
            'amount' => 4000000,
            'status' => ShmCashTransaction::STATUS_DRAFT,
            'created_by' => $this->financeUser->id,
        ]);
        $this->actingAs($this->financeUser)->post(route('shm.cash-outflow.post', $outflowTx->id));

        $finalReport = $reportService->balanceSheet('2026-11-12', AccountingContext::SHM);

        $this->assertEquals($midReport['summary']['total_assets'] - 4000000, $finalReport['summary']['total_assets']);
        $this->assertEquals($midReport['summary']['total_liabilities_and_equity'] - 4000000, $finalReport['summary']['total_liabilities_and_equity']);
        $this->assertTrue($finalReport['balanced']);
    }

    public function test_reversal_neutralizes_balance_sheet_impact(): void
    {
        $reportService = app(FinancialReportService::class);

        $tx = ShmCashTransaction::create([
            'transaction_number' => 'KM-SHM-20261112-BS4',
            'type' => ShmCashTransaction::TYPE_INFLOW,
            'transaction_date' => now()->format('Y-m-d'),
            'cash_account_id' => $this->kasShm->id,
            'account_id' => $this->hutangShm->id,
            'amount' => 8000000,
            'status' => ShmCashTransaction::STATUS_DRAFT,
            'created_by' => $this->financeUser->id,
        ]);
        $this->actingAs($this->financeUser)->post(route('shm.cash-inflow.post', $tx->id));

        // Reversal
        $this->actingAs($this->financeUser)->post(route('shm.cash-inflow.reverse', $tx->id), [
            'reason' => 'Pembatalan transaksi',
        ]);

        $postReversalReport = $reportService->balanceSheet(now()->format('Y-m-d'), AccountingContext::SHM);

        // Under JournalService::reverse(), the original journal is marked REVERSED (excluded from POSTED aggregation),
        // while the reversal journal is POSTED with inverted lines (credit 8M on kas, debit 8M on hutang).
        // Assets = -8M, Liabilities = -8M, and equation Assets = Liabilities + Equity strictly holds.
        $this->assertEquals(-8000000.0, $postReversalReport['summary']['total_assets']);
        $this->assertEquals(-8000000.0, $postReversalReport['summary']['total_liabilities_and_equity']);
        $this->assertTrue($postReversalReport['balanced']);
    }

    /* -------------------------------------------------------------------------- */
    /* Scope 8: Strict Entity & Account Isolation (Zero Cross-Entity Leakage) */
    /* -------------------------------------------------------------------------- */

    public function test_cannot_use_cooperative_chart_of_account_for_cash_account(): void
    {
        $payload = [
            'type' => ShmCashTransaction::TYPE_INFLOW,
            'transaction_date' => '2026-11-12',
            'cash_account_id' => $this->coopKas->id, // KOPERASI account
            'account_id' => $this->pendapatanShm->id,
            'amount' => 1000000,
        ];

        $response = $this->actingAs($this->financeUser)
            ->post(route('shm.cash-inflow.store'), $payload);

        $response->assertSessionHasErrors(['cash_account_id']);
    }

    public function test_cannot_use_cooperative_chart_of_account_for_counterpart_account(): void
    {
        $payload = [
            'type' => ShmCashTransaction::TYPE_INFLOW,
            'transaction_date' => '2026-11-12',
            'cash_account_id' => $this->kasShm->id,
            'account_id' => $this->coopKas->id, // KOPERASI account
            'amount' => 1000000,
        ];

        $response = $this->actingAs($this->financeUser)
            ->post(route('shm.cash-inflow.store'), $payload);

        $response->assertSessionHasErrors(['account_id']);
    }

    public function test_cannot_use_same_account_for_cash_and_counterpart(): void
    {
        $payload = [
            'type' => ShmCashTransaction::TYPE_INFLOW,
            'transaction_date' => '2026-11-12',
            'cash_account_id' => $this->kasShm->id,
            'account_id' => $this->kasShm->id, // identical
            'amount' => 1000000,
        ];

        $response = $this->actingAs($this->financeUser)
            ->post(route('shm.cash-inflow.store'), $payload);

        $response->assertSessionHasErrors(['account_id']);
    }

    public function test_shm_transactions_do_not_appear_in_cooperative_reports_or_journals(): void
    {
        $tx = ShmCashTransaction::create([
            'transaction_number' => 'KM-SHM-20261112-ISO1',
            'type' => ShmCashTransaction::TYPE_INFLOW,
            'transaction_date' => '2026-11-12',
            'cash_account_id' => $this->kasShm->id,
            'account_id' => $this->pendapatanShm->id,
            'amount' => 25000000,
            'status' => ShmCashTransaction::STATUS_DRAFT,
            'created_by' => $this->financeUser->id,
        ]);
        $this->actingAs($this->financeUser)->post(route('shm.cash-inflow.post', $tx->id));
        $tx->refresh();

        $reportService = app(FinancialReportService::class);
        $coopBalanceSheet = $reportService->balanceSheet('2026-11-12', AccountingContext::KOPERASI);

        // Cooperative Balance Sheet must NOT contain SHM transaction amounts
        $this->assertEquals(0, $coopBalanceSheet['summary']['total_assets']);
        $this->assertEquals(0, $coopBalanceSheet['summary']['total_liabilities_and_equity']);

        // Check journal entries context isolation
        $journal = JournalEntry::find($tx->journal_entry_id);
        $this->assertNotNull($journal);
        $this->assertEquals(AccountingContext::SHM, $journal->accounting_context);
        $this->assertNotEquals(AccountingContext::KOPERASI, $journal->accounting_context);
    }

    /* -------------------------------------------------------------------------- */
    /* Scope 9: Permissions & Role-Based Authorization */
    /* -------------------------------------------------------------------------- */

    public function test_unauthenticated_user_is_redirected_to_login(): void
    {
        $this->get(route('shm.cash-inflow.index'))->assertRedirect();
        $this->get(route('shm.cash-outflow.index'))->assertRedirect();
    }

    public function test_unauthorized_user_cannot_view_or_mutate_cash_flow(): void
    {
        $this->actingAs($this->unauthorizedUser)
            ->get(route('shm.cash-inflow.index'))
            ->assertForbidden();

        $this->actingAs($this->unauthorizedUser)
            ->post(route('shm.cash-inflow.store'), [
                'type' => ShmCashTransaction::TYPE_INFLOW,
                'transaction_date' => '2026-11-12',
                'cash_account_id' => $this->kasShm->id,
                'account_id' => $this->pendapatanShm->id,
                'amount' => 1000000,
            ])
            ->assertForbidden();
    }

    public function test_read_only_user_cannot_create_or_post(): void
    {
        $payload = [
            'type' => ShmCashTransaction::TYPE_INFLOW,
            'transaction_date' => '2026-11-12',
            'cash_account_id' => $this->kasShm->id,
            'account_id' => $this->pendapatanShm->id,
            'amount' => 1000000,
        ];

        // readOnlyUser has accounting.shm.journals.view but not .create
        $this->actingAs($this->readOnlyUser)
            ->post(route('shm.cash-inflow.store'), $payload)
            ->assertForbidden();
    }

    public function test_read_only_user_cannot_reverse(): void
    {
        $tx = ShmCashTransaction::create([
            'transaction_number' => 'KM-SHM-20261112-REV1',
            'type' => ShmCashTransaction::TYPE_INFLOW,
            'transaction_date' => '2026-11-12',
            'cash_account_id' => $this->kasShm->id,
            'account_id' => $this->pendapatanShm->id,
            'amount' => 1000000,
            'status' => ShmCashTransaction::STATUS_POSTED,
            'created_by' => $this->financeUser->id,
        ]);

        $this->actingAs($this->readOnlyUser)
            ->post(route('shm.cash-inflow.reverse', $tx->id), [
                'reason' => 'Test unauthorized reverse',
            ])
            ->assertForbidden();
    }

    public function test_currency_formatting_and_timestamp_representation(): void
    {
        $tx = ShmCashTransaction::create([
            'transaction_number' => 'KM-SHM-20261112-FMT1',
            'type' => ShmCashTransaction::TYPE_INFLOW,
            'transaction_date' => '2026-11-12',
            'cash_account_id' => $this->kasShm->id,
            'account_id' => $this->pendapatanShm->id,
            'amount' => 12500000,
            'status' => ShmCashTransaction::STATUS_DRAFT,
            'created_by' => $this->financeUser->id,
        ]);

        $response = $this->actingAs($this->financeUser)
            ->get(route('shm.cash-inflow.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('transactions.data.0.formatted_amount', 'Rp 12.500.000')
            ->where('transactions.data.0.timestamp', $tx->created_at->format('d/m/Y H:i'))
            ->where('transactions.data.0.nama_perkiraan', '4-1100 - Pendapatan Lain-lain SHM')
        );
    }

    public function test_draft_deletion_creates_permanent_audit_log_atomically(): void
    {
        $tx = ShmCashTransaction::create([
            'transaction_number' => 'BKK-SHM-20261113-AUD1',
            'type' => ShmCashTransaction::TYPE_OUTFLOW,
            'transaction_date' => '2026-11-13',
            'cash_account_id' => $this->kasShm->id,
            'account_id' => $this->bebanOperasionalShm->id,
            'amount' => 1500000,
            'status' => ShmCashTransaction::STATUS_DRAFT,
            'description' => 'Draft kas operasional untuk audit test',
            'created_by' => $this->financeUser->id,
        ]);

        $service = app(ShmCashFlowService::class);
        $result = $service->deleteDraft($tx, $this->financeUser->id, 'Alasan pembatalan draft audit');

        $this->assertTrue($result);
        $this->assertDatabaseMissing('shm_cash_transactions', ['id' => $tx->id]);

        $audit = AuditLog::where('object_type', 'ShmCashTransaction')
            ->where('object_id', $tx->id)
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame('shm_cash_transaction.draft_deleted', $audit->action);
        $this->assertSame('BKK-SHM-20261113-AUD1', $audit->object_label);
        $this->assertSame($this->financeUser->id, $audit->actor_id);
        $this->assertSame(1500000.0, (float) $audit->changes['amount']);
        $this->assertSame('OUTFLOW', $audit->changes['type']);
        $this->assertSame('Alasan pembatalan draft audit', $audit->changes['reason']);
    }

    public function test_draft_deletion_audit_log_rolls_back_if_database_transaction_aborts(): void
    {
        $tx = ShmCashTransaction::create([
            'transaction_number' => 'BKK-SHM-20261113-AUD2',
            'type' => ShmCashTransaction::TYPE_OUTFLOW,
            'transaction_date' => '2026-11-13',
            'cash_account_id' => $this->kasShm->id,
            'account_id' => $this->bebanOperasionalShm->id,
            'amount' => 2000000,
            'status' => ShmCashTransaction::STATUS_DRAFT,
            'created_by' => $this->financeUser->id,
        ]);

        try {
            DB::transaction(function () use ($tx) {
                app(ShmCashFlowService::class)->deleteDraft($tx, $this->financeUser->id, 'Testing rollback');
                throw new \RuntimeException('Simulasi kegagalan setelah audit log');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulasi kegagalan setelah audit log', $e->getMessage());
        }

        // Transaksi tidak terhapus karena rollback
        $this->assertDatabaseHas('shm_cash_transactions', ['id' => $tx->id]);

        // Audit log tidak boleh tersisa di database karena rollback
        $audit = AuditLog::where('object_type', 'ShmCashTransaction')
            ->where('object_id', $tx->id)
            ->first();
        $this->assertNull($audit);
    }

    public function test_posted_transaction_cannot_be_deleted_via_delete_draft(): void
    {
        $tx = ShmCashTransaction::create([
            'transaction_number' => 'BKK-SHM-20261113-AUD3',
            'type' => ShmCashTransaction::TYPE_OUTFLOW,
            'transaction_date' => '2026-11-13',
            'cash_account_id' => $this->kasShm->id,
            'account_id' => $this->bebanOperasionalShm->id,
            'amount' => 500000,
            'status' => ShmCashTransaction::STATUS_POSTED,
            'created_by' => $this->financeUser->id,
        ]);

        $this->expectException(ValidationException::class);
        app(ShmCashFlowService::class)->deleteDraft($tx, $this->financeUser->id);
    }
}
