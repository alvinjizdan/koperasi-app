<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Role;
use App\Models\ShmLoanProduct;
use App\Models\ShmProductAccounting;
use App\Models\User;
use App\Services\Accounting\ShmProductAccountingService;
use App\Support\AccountingContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ShmProductAccountingTest extends TestCase
{
    use RefreshDatabase;

    private ShmProductAccountingService $service;

    private User $admin;

    private User $staff;

    private ShmLoanProduct $product;

    private ChartOfAccount $shmCash;

    private ChartOfAccount $shmReceivable;

    private ChartOfAccount $shmInterest;

    private ChartOfAccount $shmPenalty;

    private ChartOfAccount $shmDiscount;

    private ChartOfAccount $koperasiCash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->service = app(ShmProductAccountingService::class);

        $this->admin = User::factory()->create();
        $adminRole = Role::where('name', 'admin')->first();
        if ($adminRole) {
            $this->admin->roles()->attach($adminRole->id);
        }

        $this->staff = User::factory()->create();

        // 1. Existing SHM Product
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

        $this->shmPenalty = ChartOfAccount::create([
            'accounting_context' => AccountingContext::SHM,
            'code' => '4-1201',
            'name' => 'Pendapatan Denda SHM',
            'category' => ChartOfAccount::CATEGORY_REVENUE,
            'normal_balance' => ChartOfAccount::NORMAL_CREDIT,
            'is_active' => true,
        ]);

        $this->shmDiscount = ChartOfAccount::create([
            'accounting_context' => AccountingContext::SHM,
            'code' => '4-1901',
            'name' => 'Diskon Pelunasan SHM',
            'category' => ChartOfAccount::CATEGORY_REVENUE,
            'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
            'is_active' => true,
        ]);

        // 3. Koperasi CoA Account
        $this->koperasiCash = ChartOfAccount::create([
            'accounting_context' => AccountingContext::KOPERASI,
            'code' => '1-1100',
            'name' => 'Kas Koperasi',
            'category' => ChartOfAccount::CATEGORY_ASSET,
            'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
            'is_active' => true,
        ]);
    }

    /**
     * 1. SHM Product Accounting can reference SHM CoA successfully.
     */
    public function test_shm_product_accounting_references_shm_coa(): void
    {
        $config = $this->service->saveConfiguration([
            'shm_loan_product_id' => $this->product->id,
            'cash_account_id' => $this->shmCash->id,
            'receivable_account_id' => $this->shmReceivable->id,
            'interest_revenue_account_id' => $this->shmInterest->id,
            'penalty_revenue_account_id' => $this->shmPenalty->id,
            'settlement_discount_account_id' => $this->shmDiscount->id,
            'is_active' => true,
        ], $this->admin->id);

        $this->assertNotNull($config->id);
        $this->assertEquals(AccountingContext::SHM, $config->accounting_context);
        $this->assertEquals($this->shmCash->id, $config->cash_account_id);
        $this->assertEquals($this->shmReceivable->id, $config->receivable_account_id);
    }

    /**
     * 2. Koperasi CoA cannot be referenced by SHM Product Accounting (fail-closed).
     */
    public function test_koperasi_coa_cannot_be_referenced(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->saveConfiguration([
            'shm_loan_product_id' => $this->product->id,
            'cash_account_id' => $this->koperasiCash->id, // Forbidden!
        ], $this->admin->id);
    }

    /**
     * 3. Inactive SHM CoA cannot be referenced.
     */
    public function test_inactive_shm_coa_cannot_be_referenced(): void
    {
        $inactiveAccount = ChartOfAccount::create([
            'accounting_context' => AccountingContext::SHM,
            'code' => '1-9999',
            'name' => 'Kas Nonaktif',
            'category' => ChartOfAccount::CATEGORY_ASSET,
            'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
            'is_active' => false,
        ]);

        $this->expectException(ValidationException::class);

        $this->service->saveConfiguration([
            'shm_loan_product_id' => $this->product->id,
            'cash_account_id' => $inactiveAccount->id,
        ], $this->admin->id);
    }

    /**
     * 4. Invalid account category is rejected (e.g. revenue account passed as cash asset).
     */
    public function test_invalid_account_category_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->saveConfiguration([
            'shm_loan_product_id' => $this->product->id,
            'cash_account_id' => $this->shmInterest->id, // Revenue as cash asset!
        ], $this->admin->id);
    }

    /**
     * 5. Authorized user can create and update configuration via HTTP endpoint.
     */
    public function test_authorized_user_can_save_configuration(): void
    {
        $response = $this->actingAs($this->admin)->put(route('shm.product-accounting.update', $this->product->id), [
            'cash_account_id' => $this->shmCash->id,
            'receivable_account_id' => $this->shmReceivable->id,
            'notes' => 'Konfigurasi Produk PJS resmi SK direksi',
        ]);

        $response->assertRedirect(route('shm.product-accounting.index'));

        $this->assertDatabaseHas('shm_product_accountings', [
            'shm_loan_product_id' => $this->product->id,
            'accounting_context' => AccountingContext::SHM,
            'cash_account_id' => $this->shmCash->id,
            'notes' => 'Konfigurasi Produk PJS resmi SK direksi',
        ]);
    }

    /**
     * 6. Unauthorized user cannot view or mutate Product Accounting.
     */
    public function test_unauthorized_user_cannot_access(): void
    {
        $response = $this->actingAs($this->staff)->get(route('shm.product-accounting.index'));
        $response->assertForbidden();

        $updateResponse = $this->actingAs($this->staff)->put(route('shm.product-accounting.update', $this->product->id), [
            'cash_account_id' => $this->shmCash->id,
        ]);
        $updateResponse->assertForbidden();
    }

    /**
     * 7. Safe activation and deactivation toggle.
     */
    public function test_toggle_active_status(): void
    {
        $config = $this->service->saveConfiguration([
            'shm_loan_product_id' => $this->product->id,
            'cash_account_id' => $this->shmCash->id,
            'is_active' => true,
        ], $this->admin->id);

        $this->assertTrue($config->is_active);

        $response = $this->actingAs($this->admin)->post(route('shm.product-accounting.toggle', $config->id));
        $response->assertRedirect(route('shm.product-accounting.index'));

        $this->assertFalse($config->fresh()->is_active);
    }

    /**
     * 8. Database level uniqueness enforces single configuration per product per context.
     */
    public function test_duplicate_active_configuration_rejected_by_db_unique_constraint(): void
    {
        ShmProductAccounting::create([
            'shm_loan_product_id' => $this->product->id,
            'accounting_context' => AccountingContext::SHM,
            'cash_account_id' => $this->shmCash->id,
        ]);

        $this->expectException(QueryException::class);

        ShmProductAccounting::create([
            'shm_loan_product_id' => $this->product->id,
            'accounting_context' => AccountingContext::SHM,
            'cash_account_id' => $this->shmCash->id,
        ]);
    }

    /**
     * 9. Historical Integrity: Updating Product Accounting does NOT modify historical journals.
     */
    public function test_updating_product_accounting_does_not_modify_historical_journals(): void
    {
        // 1. Create a historical journal
        $journal = JournalEntry::create([
            'accounting_context' => AccountingContext::SHM,
            'journal_number' => 'JRNL-HIST-001',
            'transaction_date' => '2026-01-01',
            'status' => JournalEntry::STATUS_POSTED,
            'posting_type' => 'manual',
            'created_by' => $this->admin->id,
        ]);

        $line = JournalLine::create([
            'journal_entry_id' => $journal->id,
            'account_id' => $this->shmCash->id,
            'debit' => 10000000,
            'credit' => 0,
            'line_order' => 1,
        ]);

        // 2. Configure product with cashAccount = shmCash
        $this->service->saveConfiguration([
            'shm_loan_product_id' => $this->product->id,
            'cash_account_id' => $this->shmCash->id,
        ], $this->admin->id);

        // 3. Create another cash account and reconfigure product
        $shmBank = ChartOfAccount::create([
            'accounting_context' => AccountingContext::SHM,
            'code' => '1-1201',
            'name' => 'Bank SHM Baru',
            'category' => ChartOfAccount::CATEGORY_ASSET,
            'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
            'is_active' => true,
        ]);

        $this->service->saveConfiguration([
            'shm_loan_product_id' => $this->product->id,
            'cash_account_id' => $shmBank->id,
        ], $this->admin->id);

        // 4. Assert historical journal line account remains untouched!
        $this->assertEquals($this->shmCash->id, $line->fresh()->account_id);
        $this->assertEquals('JRNL-HIST-001', $journal->fresh()->journal_number);
    }
}
