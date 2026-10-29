<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Role;
use App\Models\ShmAccountingMapping;
use App\Models\ShmLoanProduct;
use App\Models\ShmProductAccounting;
use App\Models\User;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\ShmAccountingMappingService;
use App\Support\AccountingContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ShmAccountingMappingAndJournalTest extends TestCase
{
    use RefreshDatabase;

    private ShmAccountingMappingService $mappingService;

    private JournalService $journalService;

    private User $admin;

    private User $staff;

    private ShmLoanProduct $product;

    private ChartOfAccount $shmCash;

    private ChartOfAccount $shmReceivable;

    private ChartOfAccount $shmInterest;

    private ChartOfAccount $koperasiCash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->mappingService = app(ShmAccountingMappingService::class);
        $this->journalService = app(JournalService::class);

        $this->admin = User::factory()->create();
        $adminRole = Role::where('name', 'admin')->first();
        if ($adminRole) {
            $this->admin->roles()->attach($adminRole->id);
        }

        $this->staff = User::factory()->create();

        // 1. Existing SHM Product & Product Accounting
        $this->product = ShmLoanProduct::firstOrCreate(
            ['code' => 'PJS'],
            ['name' => 'Pinjaman Siaga', 'category' => ShmLoanProduct::CATEGORY_PERUMAHAN, 'is_active' => true]
        );

        // 2. Active SHM CoA Accounts
        $this->shmCash = ChartOfAccount::create([
            'accounting_context' => AccountingContext::SHM,
            'code' => '1-1101',
            'name' => 'Kas Operasional SHM',
            'category' => ChartOfAccount::CATEGORY_ASSET,
            'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
            'is_active' => true,
        ]);

        $this->shmReceivable = ChartOfAccount::create([
            'accounting_context' => AccountingContext::SHM,
            'code' => '1-1301',
            'name' => 'Piutang Pokok SHM',
            'category' => ChartOfAccount::CATEGORY_ASSET,
            'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
            'is_active' => true,
        ]);

        $this->shmInterest = ChartOfAccount::create([
            'accounting_context' => AccountingContext::SHM,
            'code' => '4-1101',
            'name' => 'Pendapatan Bunga SHM',
            'category' => ChartOfAccount::CATEGORY_REVENUE,
            'normal_balance' => ChartOfAccount::NORMAL_CREDIT,
            'is_active' => true,
        ]);

        // 3. Koperasi CoA
        $this->koperasiCash = ChartOfAccount::create([
            'accounting_context' => AccountingContext::KOPERASI,
            'code' => '1-1100',
            'name' => 'Kas Koperasi',
            'category' => ChartOfAccount::CATEGORY_ASSET,
            'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
            'is_active' => true,
        ]);

        // 4. Setup Product Accounting
        ShmProductAccounting::create([
            'shm_loan_product_id' => $this->product->id,
            'accounting_context' => AccountingContext::SHM,
            'cash_account_id' => $this->shmCash->id,
            'receivable_account_id' => $this->shmReceivable->id,
            'interest_revenue_account_id' => $this->shmInterest->id,
            'is_active' => true,
        ]);
    }

    // ==========================================
    // 1. MAPPING TESTS
    // ==========================================

    public function test_shm_mapping_creation_with_role(): void
    {
        $mapping = $this->mappingService->saveMapping([
            'business_event' => ShmAccountingMapping::EVENT_DISBURSEMENT,
            'shm_loan_product_id' => $this->product->id,
            'debit_account_role' => ShmAccountingMapping::ROLE_RECEIVABLE,
            'credit_account_role' => ShmAccountingMapping::ROLE_CASH,
            'is_active' => true,
        ], $this->admin->id);

        $this->assertNotNull($mapping->id);
        $this->assertEquals(AccountingContext::SHM, $mapping->accounting_context);
        $this->assertEquals(ShmAccountingMapping::ROLE_RECEIVABLE, $mapping->debit_account_role);
    }

    public function test_mapping_cooperative_account_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->mappingService->saveMapping([
            'business_event' => ShmAccountingMapping::EVENT_DISBURSEMENT,
            'debit_account_id' => $this->koperasiCash->id, // Forbidden!
            'credit_account_role' => ShmAccountingMapping::ROLE_CASH,
        ], $this->admin->id);
    }

    public function test_mapping_inactive_account_rejected(): void
    {
        $inactive = ChartOfAccount::create([
            'accounting_context' => AccountingContext::SHM,
            'code' => '1-9999',
            'name' => 'Kas Nonaktif',
            'category' => ChartOfAccount::CATEGORY_ASSET,
            'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
            'is_active' => false,
        ]);

        $this->expectException(ValidationException::class);

        $this->mappingService->saveMapping([
            'business_event' => ShmAccountingMapping::EVENT_DISBURSEMENT,
            'debit_account_id' => $inactive->id,
            'credit_account_role' => ShmAccountingMapping::ROLE_CASH,
        ], $this->admin->id);
    }

    public function test_duplicate_mapping_rejected(): void
    {
        $this->mappingService->saveMapping([
            'business_event' => ShmAccountingMapping::EVENT_DISBURSEMENT,
            'shm_loan_product_id' => $this->product->id,
            'debit_account_role' => ShmAccountingMapping::ROLE_RECEIVABLE,
            'credit_account_role' => ShmAccountingMapping::ROLE_CASH,
        ], $this->admin->id);

        $this->expectException(ValidationException::class);

        $this->mappingService->saveMapping([
            'business_event' => ShmAccountingMapping::EVENT_DISBURSEMENT,
            'shm_loan_product_id' => $this->product->id,
            'debit_account_role' => ShmAccountingMapping::ROLE_RECEIVABLE,
            'credit_account_role' => ShmAccountingMapping::ROLE_CASH,
        ], $this->admin->id);
    }

    public function test_duplicate_generic_mapping_rejected_at_app_and_db_level(): void
    {
        // 1. First generic mapping succeeds
        $first = $this->mappingService->saveMapping([
            'business_event' => ShmAccountingMapping::EVENT_REGULAR_PAYMENT,
            'shm_loan_product_id' => null, // Generic!
            'debit_account_role' => ShmAccountingMapping::ROLE_CASH,
            'credit_account_role' => ShmAccountingMapping::ROLE_RECEIVABLE,
        ], $this->admin->id);
        $this->assertNotNull($first->id);

        // 2. Second generic mapping via service is rejected by application validation
        try {
            $this->mappingService->saveMapping([
                'business_event' => ShmAccountingMapping::EVENT_REGULAR_PAYMENT,
                'shm_loan_product_id' => null, // Duplicate Generic!
                'debit_account_role' => ShmAccountingMapping::ROLE_CASH,
                'credit_account_role' => ShmAccountingMapping::ROLE_RECEIVABLE,
            ], $this->admin->id);
            $this->fail('Expected ValidationException was not thrown');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('business_event', $e->errors());
        }

        // 3. Direct DB insert bypassing application layer is rejected by partial unique index
        $this->expectException(QueryException::class);
        ShmAccountingMapping::create([
            'accounting_context' => AccountingContext::SHM,
            'business_event' => ShmAccountingMapping::EVENT_REGULAR_PAYMENT,
            'shm_loan_product_id' => null, // Duplicate Generic bypassing service!
            'debit_account_role' => ShmAccountingMapping::ROLE_CASH,
            'credit_account_role' => ShmAccountingMapping::ROLE_RECEIVABLE,
        ]);
    }

    public function test_duplicate_product_specific_mapping_rejected_at_db_level(): void
    {
        ShmAccountingMapping::create([
            'accounting_context' => AccountingContext::SHM,
            'business_event' => ShmAccountingMapping::EVENT_DISBURSEMENT,
            'shm_loan_product_id' => $this->product->id,
            'debit_account_role' => ShmAccountingMapping::ROLE_RECEIVABLE,
            'credit_account_role' => ShmAccountingMapping::ROLE_CASH,
        ]);

        $this->expectException(QueryException::class);
        ShmAccountingMapping::create([
            'accounting_context' => AccountingContext::SHM,
            'business_event' => ShmAccountingMapping::EVENT_DISBURSEMENT,
            'shm_loan_product_id' => $this->product->id,
            'debit_account_role' => ShmAccountingMapping::ROLE_RECEIVABLE,
            'credit_account_role' => ShmAccountingMapping::ROLE_CASH,
        ]);
    }

    public function test_generic_and_product_specific_mappings_coexist(): void
    {
        // Generic mapping
        $generic = $this->mappingService->saveMapping([
            'business_event' => ShmAccountingMapping::EVENT_REGULAR_PAYMENT,
            'shm_loan_product_id' => null,
            'debit_account_role' => ShmAccountingMapping::ROLE_CASH,
            'credit_account_role' => ShmAccountingMapping::ROLE_RECEIVABLE,
        ], $this->admin->id);

        // Product specific mapping for same event
        $specific = $this->mappingService->saveMapping([
            'business_event' => ShmAccountingMapping::EVENT_REGULAR_PAYMENT,
            'shm_loan_product_id' => $this->product->id,
            'debit_account_role' => ShmAccountingMapping::ROLE_CASH,
            'credit_account_role' => ShmAccountingMapping::ROLE_INTEREST_REVENUE,
        ], $this->admin->id);

        $this->assertNotNull($generic->id);
        $this->assertNotNull($specific->id);
        $this->assertNotEquals($generic->id, $specific->id);
    }

    public function test_different_products_and_events_coexist(): void
    {
        $product2 = ShmLoanProduct::firstOrCreate(
            ['code' => 'PJK'],
            ['name' => 'Pinjaman Kendaraan', 'category' => ShmLoanProduct::CATEGORY_PERUMAHAN, 'is_active' => true]
        );

        // Product 1
        $map1 = $this->mappingService->saveMapping([
            'business_event' => ShmAccountingMapping::EVENT_DISBURSEMENT,
            'shm_loan_product_id' => $this->product->id,
            'debit_account_role' => ShmAccountingMapping::ROLE_RECEIVABLE,
            'credit_account_role' => ShmAccountingMapping::ROLE_CASH,
        ], $this->admin->id);

        // Product 2 for same event
        $map2 = $this->mappingService->saveMapping([
            'business_event' => ShmAccountingMapping::EVENT_DISBURSEMENT,
            'shm_loan_product_id' => $product2->id,
            'debit_account_role' => ShmAccountingMapping::ROLE_RECEIVABLE,
            'credit_account_role' => ShmAccountingMapping::ROLE_CASH,
        ], $this->admin->id);

        // Product 1 for different event
        $map3 = $this->mappingService->saveMapping([
            'business_event' => ShmAccountingMapping::EVENT_SETTLEMENT,
            'shm_loan_product_id' => $this->product->id,
            'debit_account_role' => ShmAccountingMapping::ROLE_CASH,
            'credit_account_role' => ShmAccountingMapping::ROLE_RECEIVABLE,
        ], $this->admin->id);

        $this->assertNotNull($map1->id);
        $this->assertNotNull($map2->id);
        $this->assertNotNull($map3->id);
    }

    public function test_resolver_prefers_product_specific_and_falls_back_to_generic(): void
    {
        // 1. Create generic mapping
        $this->mappingService->saveMapping([
            'business_event' => ShmAccountingMapping::EVENT_REGULAR_PAYMENT,
            'shm_loan_product_id' => null,
            'debit_account_role' => ShmAccountingMapping::ROLE_CASH,
            'credit_account_role' => ShmAccountingMapping::ROLE_RECEIVABLE, // Credit is Receivable for generic
        ], $this->admin->id);

        // Resolve without product-specific -> falls back to generic
        $fallback = $this->mappingService->resolveAccountsForEvent(ShmAccountingMapping::EVENT_REGULAR_PAYMENT, $this->product->id);
        $this->assertEquals($this->shmReceivable->id, $fallback['credit_account']->id);

        // 2. Now add product-specific mapping (Credit is Interest Revenue)
        $this->mappingService->saveMapping([
            'business_event' => ShmAccountingMapping::EVENT_REGULAR_PAYMENT,
            'shm_loan_product_id' => $this->product->id,
            'debit_account_role' => ShmAccountingMapping::ROLE_CASH,
            'credit_account_role' => ShmAccountingMapping::ROLE_INTEREST_REVENUE,
        ], $this->admin->id);

        // Resolve with product -> prefers product-specific!
        $preferred = $this->mappingService->resolveAccountsForEvent(ShmAccountingMapping::EVENT_REGULAR_PAYMENT, $this->product->id);
        $this->assertEquals($this->shmInterest->id, $preferred['credit_account']->id);
    }

    public function test_mapping_account_resolution_via_product_accounting(): void
    {
        $this->mappingService->saveMapping([
            'business_event' => ShmAccountingMapping::EVENT_DISBURSEMENT,
            'shm_loan_product_id' => $this->product->id,
            'debit_account_role' => ShmAccountingMapping::ROLE_RECEIVABLE,
            'credit_account_role' => ShmAccountingMapping::ROLE_CASH,
        ], $this->admin->id);

        $resolved = $this->mappingService->resolveAccountsForEvent(ShmAccountingMapping::EVENT_DISBURSEMENT, $this->product->id);

        $this->assertEquals($this->shmReceivable->id, $resolved['debit_account']->id);
        $this->assertEquals($this->shmCash->id, $resolved['credit_account']->id);
    }

    public function test_unauthorized_user_cannot_manage_mapping(): void
    {
        $response = $this->actingAs($this->staff)->get(route('shm.accounting-mapping.index'));
        $response->assertForbidden();

        $createResponse = $this->actingAs($this->staff)->post(route('shm.accounting-mapping.store'), [
            'business_event' => ShmAccountingMapping::EVENT_DISBURSEMENT,
        ]);
        $createResponse->assertForbidden();
    }

    // ==========================================
    // 2. JOURNAL ENGINE TESTS
    // ==========================================

    public function test_valid_balanced_shm_journal_creation_and_posting(): void
    {
        $journal = $this->journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => '2026-10-29',
            'description' => 'Test Jurnal SHM',
        ], [
            ['account_id' => $this->shmReceivable->id, 'debit' => 10000000, 'credit' => 0],
            ['account_id' => $this->shmCash->id, 'debit' => 0, 'credit' => 10000000],
        ], $this->admin->id);

        $this->assertEquals(JournalEntry::STATUS_DRAFT, $journal->status);
        $this->assertEquals(AccountingContext::SHM, $journal->accounting_context);
        $this->assertTrue($journal->isBalanced());

        $posted = $this->journalService->post($journal, $this->admin->id);
        $this->assertEquals(JournalEntry::STATUS_POSTED, $posted->status);
        $this->assertNotNull($posted->posted_at);
        $this->assertEquals($this->admin->id, $posted->posted_by);
    }

    public function test_unbalanced_journal_posting_rejected(): void
    {
        $journal = $this->journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => '2026-10-29',
            'description' => 'Unbalanced Test',
        ], [
            ['account_id' => $this->shmReceivable->id, 'debit' => 10000000, 'credit' => 0],
            ['account_id' => $this->shmCash->id, 'debit' => 0, 'credit' => 9000000], // Unbalanced!
        ], $this->admin->id);

        $this->expectException(ValidationException::class);
        $this->journalService->post($journal, $this->admin->id);
    }

    public function test_single_line_journal_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => '2026-10-29',
        ], [
            ['account_id' => $this->shmReceivable->id, 'debit' => 10000000, 'credit' => 0],
        ], $this->admin->id);
    }

    public function test_shm_journal_with_koperasi_account_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => '2026-10-29',
        ], [
            ['account_id' => $this->shmReceivable->id, 'debit' => 10000000, 'credit' => 0],
            ['account_id' => $this->koperasiCash->id, 'debit' => 0, 'credit' => 10000000], // Cross-context!
        ], $this->admin->id);
    }

    public function test_line_with_both_debit_and_credit_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => '2026-10-29',
        ], [
            ['account_id' => $this->shmReceivable->id, 'debit' => 10000000, 'credit' => 500000], // Both!
            ['account_id' => $this->shmCash->id, 'debit' => 0, 'credit' => 9500000],
        ], $this->admin->id);
    }

    public function test_posted_journal_cannot_be_updated_or_mutated(): void
    {
        $journal = $this->journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => '2026-10-29',
        ], [
            ['account_id' => $this->shmReceivable->id, 'debit' => 10000000, 'credit' => 0],
            ['account_id' => $this->shmCash->id, 'debit' => 0, 'credit' => 10000000],
        ], $this->admin->id);

        $posted = $this->journalService->post($journal, $this->admin->id);

        $this->expectException(ValidationException::class);

        $this->journalService->updateDraft($posted, [
            'transaction_date' => '2026-11-01',
        ], [
            ['account_id' => $this->shmReceivable->id, 'debit' => 20000000, 'credit' => 0],
            ['account_id' => $this->shmCash->id, 'debit' => 0, 'credit' => 20000000],
        ]);
    }

    public function test_journal_reversal_creates_compensating_journal(): void
    {
        $journal = $this->journalService->createDraft([
            'accounting_context' => AccountingContext::SHM,
            'transaction_date' => '2026-10-29',
            'description' => 'Pencairan Asli',
        ], [
            ['account_id' => $this->shmReceivable->id, 'debit' => 10000000, 'credit' => 0],
            ['account_id' => $this->shmCash->id, 'debit' => 0, 'credit' => 10000000],
        ], $this->admin->id);

        $posted = $this->journalService->post($journal, $this->admin->id);

        $reversal = $this->journalService->reverse($posted, $this->admin->id, 'Salah nominal pencairan');

        $this->assertEquals(JournalEntry::STATUS_REVERSED, $posted->fresh()->status);
        $this->assertEquals(JournalEntry::STATUS_POSTED, $reversal->status);
        $this->assertEquals($reversal->id, $posted->fresh()->reversed_by_entry_id);

        // Assert inverted amounts
        $lines = $reversal->lines()->orderBy('line_order')->get();
        $this->assertEquals($this->shmReceivable->id, $lines[0]->account_id);
        $this->assertEquals(0, (float) $lines[0]->debit);
        $this->assertEquals(10000000, (float) $lines[0]->credit);

        $this->assertEquals($this->shmCash->id, $lines[1]->account_id);
        $this->assertEquals(10000000, (float) $lines[1]->debit);
        $this->assertEquals(0, (float) $lines[1]->credit);
    }
}
