<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\ShmApplication;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanProduct;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithShmDisbursement;
use Tests\TestCase;

class ShmPermissionRoleMatrixVerificationTest extends TestCase
{
    use InteractsWithShmDisbursement;
    use RefreshDatabase;

    private User $superadmin;

    private User $admin;

    private User $marketing;

    private User $kasir;

    private User $pengawas;

    private User $unauthorizedUser;

    private ShmLoanProduct $product;

    private ShmCustomer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        // 1. Superadmin User
        $this->superadmin = User::factory()->create();
        $this->superadmin->roles()->attach(Role::where('name', 'superadmin')->value('id'));

        // 2. Admin User
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('name', 'admin')->value('id'));

        // 3. Marketing User
        $this->marketing = User::factory()->create();
        $this->marketing->roles()->attach(Role::where('name', 'marketing')->value('id'));

        // 4. Kasir User
        $this->kasir = User::factory()->create();
        $this->kasir->roles()->attach(Role::where('name', 'kasir')->value('id'));

        // 5. Pengawas User
        $this->pengawas = User::factory()->create();
        $this->pengawas->roles()->attach(Role::where('name', 'pengawas')->value('id'));

        // 6. User without any roles
        $this->unauthorizedUser = User::factory()->create();

        $this->seedShmSavingsProducts();

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
    }

    /**
     * Helper to create a sample loan
     */
    private function createSampleLoan(): ShmLoan
    {
        $mirror = ShmApplication::create([
            'application_number' => 'APP-MAT-'.Str::upper(Str::random(6)),
            'shm_customer_id' => $this->customer->id,
            'marketing_id' => $this->marketing->id,
            'status' => 'approved',
        ]);

        $loanApp = ShmLoanApplication::create([
            'application_number' => 'PENGAJ-MAT-'.Str::upper(Str::random(8)),
            'shm_customer_id' => $this->customer->id,
            'shm_loan_product_id' => $this->product->id,
            'shm_application_id' => $mirror->id,
            'application_date' => now()->toDateString(),
            'principal' => 10000000,
            'approved_amount' => 10000000,
            'tenor_months' => 12,
            'first_due_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'marketing_id' => $this->marketing->id,
            'status' => 'approved',
        ]);

        return ShmLoan::create([
            'loan_number' => 'SHM-LOAN-'.Str::upper(Str::random(6)),
            'shm_customer_id' => $this->customer->id,
            'shm_loan_product_id' => $this->product->id,
            'shm_loan_application_id' => $loanApp->id,
            'principal' => 10000000,
            'tenor_months' => 12,
            'rate_percent' => 1.30,
            'rate_period' => 'monthly',
            'calculation_method' => 'flat',
            'disbursed_at' => now()->toDateString(),
            'first_due_date' => now()->addMonth()->toDateString(),
            'status' => ShmLoan::STATUS_ACTIVE,
        ]);
    }

    // =========================================================================
    // 1. Superadmin Ownership over Role & Permission Configuration
    // =========================================================================

    public function test_superadmin_and_admin_can_manage_roles_and_unauthorized_is_forbidden(): void
    {
        // Superadmin bypasses and accesses role management
        $this->actingAs($this->superadmin)->get(route('pengaturan.roles.index'))->assertOk();
        $this->actingAs($this->superadmin)->get(route('pengaturan.roles.permissions'))->assertOk();

        // Admin has role.view permission by default
        $this->actingAs($this->admin)->get(route('pengaturan.roles.index'))->assertOk();

        // Marketing, Kasir, Pengawas, and Unauthenticated/Unauthorized are rejected
        $this->actingAs($this->marketing)->get(route('pengaturan.roles.index'))->assertForbidden();
        $this->actingAs($this->kasir)->get(route('pengaturan.roles.index'))->assertForbidden();
        $this->actingAs($this->pengawas)->get(route('pengaturan.roles.index'))->assertForbidden();
        $this->actingAs($this->unauthorizedUser)->get(route('pengaturan.roles.index'))->assertForbidden();

        // Direct HTTP mutation protection: create role
        $this->actingAs($this->unauthorizedUser)->post(route('pengaturan.roles.store'), [
            'name' => 'attacker_role',
            'display_name' => 'Attacker',
        ])->assertForbidden();

        $this->actingAs($this->marketing)->post(route('pengaturan.roles.store'), [
            'name' => 'attacker_role',
            'display_name' => 'Attacker',
        ])->assertForbidden();
    }

    // =========================================================================
    // 2. SHM Accounting Server-Side Protection (No Dedicated Accounting Role)
    // =========================================================================

    public function test_shm_accounting_coa_permissions(): void
    {
        // 1. View / Index & Export
        $this->actingAs($this->superadmin)->get(route('shm.coa.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('shm.coa.index'))->assertOk();
        $this->actingAs($this->superadmin)->get(route('shm.coa.export'))->assertOk();

        // Kasir & Marketing do not have accounting.shm.view
        $this->actingAs($this->marketing)->get(route('shm.coa.index'))->assertForbidden();
        $this->actingAs($this->kasir)->get(route('shm.coa.index'))->assertForbidden();
        $this->actingAs($this->unauthorizedUser)->get(route('shm.coa.index'))->assertForbidden();
        $this->actingAs($this->unauthorizedUser)->get(route('shm.coa.export'))->assertForbidden();

        // 2. Create & Store Mutation
        $this->actingAs($this->superadmin)->get(route('shm.coa.create'))->assertOk();
        $this->actingAs($this->admin)->get(route('shm.coa.create'))->assertOk();

        $this->actingAs($this->marketing)->get(route('shm.coa.create'))->assertForbidden();
        $this->actingAs($this->marketing)->post(route('shm.coa.store'), [
            'code' => '1-9999',
            'name' => 'Illegal Account',
            'category' => 'asset',
            'normal_balance' => 'debit',
        ])->assertForbidden();

        $this->actingAs($this->unauthorizedUser)->post(route('shm.coa.store'), [
            'code' => '1-9999',
            'name' => 'Illegal Account',
            'category' => 'asset',
            'normal_balance' => 'debit',
        ])->assertForbidden();
    }

    public function test_shm_product_accounting_and_mapping_permissions(): void
    {
        // Product Accounting routes
        $this->actingAs($this->superadmin)->get(route('shm.product-accounting.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('shm.product-accounting.index'))->assertOk();
        $this->actingAs($this->marketing)->get(route('shm.product-accounting.index'))->assertForbidden();
        $this->actingAs($this->unauthorizedUser)->get(route('shm.product-accounting.index'))->assertForbidden();

        // Accounting Mapping routes
        $this->actingAs($this->superadmin)->get(route('shm.accounting-mapping.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('shm.accounting-mapping.index'))->assertOk();
        $this->actingAs($this->marketing)->get(route('shm.accounting-mapping.index'))->assertForbidden();
        $this->actingAs($this->unauthorizedUser)->get(route('shm.accounting-mapping.index'))->assertForbidden();

        // Direct HTTP mutation on mappings
        $this->actingAs($this->unauthorizedUser)->post(route('shm.accounting-mapping.store'), [
            'business_event' => 'SHM_LOAN_DISBURSEMENT',
        ])->assertForbidden();
    }

    public function test_shm_financial_reports_and_xlsx_permissions(): void
    {
        $reportRoutes = [
            'shm.trial-balance.index',
            'shm.trial-balance.export',
            'shm.period-profit-loss.index',
            'shm.period-profit-loss.export',
            'shm.cumulative-profit-loss.index',
            'shm.cumulative-profit-loss.export',
            'shm.balance-sheet.index',
            'shm.balance-sheet.export',
        ];

        foreach ($reportRoutes as $route) {
            // Authorized roles: Superadmin & Admin
            $this->actingAs($this->superadmin)->get(route($route))->assertOk();
            $this->actingAs($this->admin)->get(route($route))->assertOk();

            // Unauthorized roles: Marketing, Kasir, Pengawas (unless explicitly assigned accounting.shm.reports.view)
            $this->actingAs($this->marketing)->get(route($route))->assertForbidden();
            $this->actingAs($this->kasir)->get(route($route))->assertForbidden();
            $this->actingAs($this->unauthorizedUser)->get(route($route))->assertForbidden();
        }
    }

    // =========================================================================
    // 3. SHM Business Operations Server-Side Protection
    // =========================================================================

    public function test_shm_business_operations_permissions(): void
    {
        $loan = $this->createSampleLoan();

        // Payment view and mutation
        // Marketing has shm.view
        $this->actingAs($this->marketing)->get(route('shm.loans.payments.index', $loan))->assertOk();
        $this->actingAs($this->unauthorizedUser)->get(route('shm.loans.payments.index', $loan))->assertForbidden();

        // Payment record mutation requires shm.payment.create (only Admin / Superadmin by default; Marketing doesn't have it)
        $this->actingAs($this->marketing)->post(route('shm.loans.payments.store', $loan), [
            'payment_date' => now()->toDateString(),
            'amount' => 500000,
        ])->assertForbidden();

        $this->actingAs($this->unauthorizedUser)->post(route('shm.loans.payments.store', $loan), [
            'payment_date' => now()->toDateString(),
            'amount' => 500000,
        ])->assertForbidden();

        // Settlement view requires shm.view (Marketing has it, Unauthorized does not)
        $this->actingAs($this->marketing)->get(route('shm.loans.pelunasan.show', $loan))->assertOk();
        $this->actingAs($this->unauthorizedUser)->get(route('shm.loans.pelunasan.show', $loan))->assertForbidden();

        // Settlement execution mutations require shm.settlement.create (Marketing does NOT have it, Admin/Superadmin do)
        $this->actingAs($this->marketing)->post(route('shm.loans.pelunasan.approve', $loan))->assertForbidden();
        $this->actingAs($this->unauthorizedUser)->post(route('shm.loans.pelunasan.approve', $loan))->assertForbidden();
        $this->actingAs($this->marketing)->post(route('shm.loans.pelunasan.complete', $loan))->assertForbidden();
        $this->actingAs($this->unauthorizedUser)->post(route('shm.loans.pelunasan.complete', $loan))->assertForbidden();
    }

    // =========================================================================
    // 4. Collection Server-Side Protection (Marketing Ownership, No Collector Role)
    // =========================================================================

    public function test_collection_permissions_and_ownership_guards(): void
    {
        // Collection work queue index: requires shm.collection.view
        $this->actingAs($this->marketing)->get(route('shm.collection.index'))->assertOk();
        $this->actingAs($this->superadmin)->get(route('shm.collection.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('shm.collection.index'))->assertOk();

        $this->actingAs($this->kasir)->get(route('shm.collection.index'))->assertForbidden();
        $this->actingAs($this->pengawas)->get(route('shm.collection.index'))->assertForbidden();
        $this->actingAs($this->unauthorizedUser)->get(route('shm.collection.index'))->assertForbidden();
    }

    // =========================================================================
    // 5. Verification that No Collector and No SHM Accounting Role Exists
    // =========================================================================

    public function test_no_collector_and_no_dedicated_shm_accounting_role_in_database(): void
    {
        $this->assertDatabaseMissing('roles', ['name' => 'collector']);
        $this->assertDatabaseMissing('roles', ['name' => 'shm_collector']);
        $this->assertDatabaseMissing('roles', ['name' => 'shm_accountant']);
        $this->assertDatabaseMissing('roles', ['name' => 'shm_accounting_admin']);
        $this->assertDatabaseMissing('roles', ['name' => 'accounting_shm']);
    }
}
