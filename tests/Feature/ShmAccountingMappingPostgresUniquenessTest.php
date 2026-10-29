<?php

namespace Tests\Feature;

use App\Models\ShmLoanProduct;
use App\Models\User;
use App\Services\Accounting\ShmAccountingMappingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Dedicated Mapping Partial Index Uniqueness Test (T006D-FIX).
 */
class ShmAccountingMappingPostgresUniquenessTest extends TestCase
{
    use RefreshDatabase;

    private ShmAccountingMappingService $mappingService;

    private User $admin;

    private ShmLoanProduct $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->mappingService = app(ShmAccountingMappingService::class);
        $this->admin = User::first() ?? User::factory()->create();

        $this->product = ShmLoanProduct::first() ?? ShmLoanProduct::create([
            'code' => 'TEST-PROD-01',
            'name' => 'Produk Test Uniqueness',
            'category' => ShmLoanProduct::CATEGORY_PERUMAHAN,
            'is_active' => true,
        ]);
    }

    public function test_partial_unique_index_rejects_duplicate_generic_mappings(): void
    {
        // 1. First insert with shm_loan_product_id = NULL
        DB::table('shm_accounting_mappings')->insert([
            'accounting_context' => 'shm_test',
            'business_event' => 'SHM_TEST_EVENT_GENERIC',
            'shm_loan_product_id' => null,
            'debit_account_role' => 'cash',
            'credit_account_role' => 'receivable',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Second insert with identical context + event + NULL product MUST violate shm_mapping_generic_unique
        $this->expectException(QueryException::class);
        DB::table('shm_accounting_mappings')->insert([
            'accounting_context' => 'shm_test',
            'business_event' => 'SHM_TEST_EVENT_GENERIC',
            'shm_loan_product_id' => null,
            'debit_account_role' => 'cash',
            'credit_account_role' => 'receivable',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_partial_unique_index_rejects_duplicate_product_specific_mappings(): void
    {
        // 1. First insert with specific product
        DB::table('shm_accounting_mappings')->insert([
            'accounting_context' => 'shm_test',
            'business_event' => 'SHM_TEST_EVENT_SPECIFIC',
            'shm_loan_product_id' => $this->product->id,
            'debit_account_role' => 'cash',
            'credit_account_role' => 'receivable',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Second insert with identical context + event + product MUST violate shm_mapping_product_specific_unique
        $this->expectException(QueryException::class);
        DB::table('shm_accounting_mappings')->insert([
            'accounting_context' => 'shm_test',
            'business_event' => 'SHM_TEST_EVENT_SPECIFIC',
            'shm_loan_product_id' => $this->product->id,
            'debit_account_role' => 'cash',
            'credit_account_role' => 'receivable',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_allows_generic_and_product_specific_coexistence(): void
    {
        // 1. Generic mapping (NULL)
        DB::table('shm_accounting_mappings')->insert([
            'accounting_context' => 'shm_test',
            'business_event' => 'SHM_TEST_COEXIST',
            'shm_loan_product_id' => null,
            'debit_account_role' => 'cash',
            'credit_account_role' => 'receivable',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Product-specific mapping for same event
        DB::table('shm_accounting_mappings')->insert([
            'accounting_context' => 'shm_test',
            'business_event' => 'SHM_TEST_COEXIST',
            'shm_loan_product_id' => $this->product->id,
            'debit_account_role' => 'cash',
            'credit_account_role' => 'receivable',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $count = DB::table('shm_accounting_mappings')
            ->where('accounting_context', 'shm_test')
            ->where('business_event', 'SHM_TEST_COEXIST')
            ->count();

        $this->assertEquals(2, $count);
    }
}
