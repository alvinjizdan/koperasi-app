<?php

namespace Tests\Feature;

use App\Enums\EntityContext;
use App\Enums\ShmApplicationStatus;
use App\Models\Role;
use App\Models\ShmApplication;
use App\Models\ShmCustomer;
use App\Models\ShmMemorandum;
use App\Models\User;
use Database\Seeders\MakroPhysicalRoleSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleBasedUserAcceptanceShmAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $direktur;

    private User $branchManager;

    private User $finance;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(MakroPhysicalRoleSeeder::class);

        $this->direktur = User::factory()->create(['name' => 'Presiden Direktur Makro', 'email' => 'direktur@example.com', 'is_active' => true]);
        $this->direktur->roles()->attach(Role::where('name', 'direktur_utama_makro')->value('id'));

        $this->branchManager = User::factory()->create(['name' => 'Branch Manager Makro', 'email' => 'bm@example.com', 'is_active' => true]);
        $this->branchManager->roles()->attach(Role::where('name', 'branch_manager_makro')->value('id'));

        $this->finance = User::factory()->create(['name' => 'Finance Operational Makro', 'email' => 'finance@example.com', 'is_active' => true]);
        $this->finance->roles()->attach(Role::where('name', 'finance_operational_makro')->value('id'));

        $this->superadmin = User::factory()->create(['name' => 'Superadmin', 'email' => 'superadmin@example.com', 'is_active' => true]);
        $this->superadmin->roles()->attach(Role::where('name', 'superadmin')->value('id'));
    }

    // =========================================================================
    // Scope A: Login, Dashboard & Entity Context
    // =========================================================================

    public function test_scope_a_all_three_roles_can_access_makro_dashboard(): void
    {
        $this->actingAs($this->direktur)->get(route('shm.dashboard'))->assertOk();
        $this->actingAs($this->branchManager)->get(route('shm.dashboard'))->assertOk();
        $this->actingAs($this->finance)->get(route('shm.dashboard'))->assertOk();
    }

    public function test_scope_a_unauthenticated_user_redirected_to_shm_login(): void
    {
        $this->get(route('shm.dashboard'))->assertRedirect(route('shm.login'));
    }

    public function test_scope_a_cross_entity_isolation_makro_users_cannot_access_mikro_routes(): void
    {
        $this->actingAs($this->direktur)->get('/anggota')->assertNotFound();
        $this->actingAs($this->branchManager)->get('/anggota')->assertNotFound();
        $this->actingAs($this->finance)->get('/anggota')->assertNotFound();

        $this->actingAs($this->direktur)->get('/simpanan')->assertNotFound();
        $this->actingAs($this->branchManager)->get('/simpanan')->assertNotFound();
        $this->actingAs($this->finance)->get('/simpanan')->assertNotFound();

        $this->actingAs($this->direktur)->get('/pinjaman')->assertNotFound();
        $this->actingAs($this->branchManager)->get('/pinjaman')->assertNotFound();
        $this->actingAs($this->finance)->get('/pinjaman')->assertNotFound();
    }

    // =========================================================================
    // Scope B: Branch Manager Makro Operational Capabilities & Boundaries
    // =========================================================================

    public function test_scope_b_branch_manager_can_access_operational_modules(): void
    {
        $this->actingAs($this->branchManager)->get(route('shm.customers.index'))->assertOk();
        $this->actingAs($this->branchManager)->get(route('shm.agents.index'))->assertOk();
        $this->actingAs($this->branchManager)->get(route('shm.credit-checks.index'))->assertOk();
        $this->actingAs($this->branchManager)->get(route('shm.credit-checks.results'))->assertOk();
        $this->actingAs($this->branchManager)->get(route('shm.applications.index'))->assertOk();
        $this->actingAs($this->branchManager)->get(route('shm.memorandums.shm-shgb.index'))->assertOk();
        $this->actingAs($this->branchManager)->get(route('shm.memorandums.buyback.index'))->assertOk();
        $this->actingAs($this->branchManager)->get(route('shm.memorandums.summary.index'))->assertOk();
        $this->actingAs($this->branchManager)->get(route('shm.collection.index'))->assertOk();
        $this->actingAs($this->branchManager)->get(route('shm.exports.masuk'))->assertOk();
    }

    public function test_scope_b_branch_manager_cannot_perform_director_approval(): void
    {
        $this->assertFalse($this->branchManager->hasPermission('shm.approval.decide', EntityContext::Makro));

        // BM can view the approval queue to review and perform Level 1 approvals
        $this->actingAs($this->branchManager)->get(route('shm.approvals.index'))->assertOk();

        $customer = ShmCustomer::factory()->create();
        $app = ShmApplication::create([
            'application_number' => 'APP-BM-TEST-'.uniqid(),
            'shm_customer_id' => $customer->id,
            'marketing_id' => $this->branchManager->id,
            'type' => 'shgb',
            'status' => 'approval',
        ]);

        // BM is forbidden from executing Level 2 Director final approval
        $this->actingAs($this->branchManager)
            ->post(route('shm.applications.approve', $app->id), ['notes' => 'BM illegal approval attempt'])
            ->assertForbidden();

        $memo = ShmMemorandum::create([
            'shm_application_id' => $app->id,
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'status' => ShmMemorandum::STATUS_UNDER_REVIEW,
            'created_by' => $this->branchManager->id,
        ]);

        // BM is forbidden from executing memorandum approval
        $this->actingAs($this->branchManager)
            ->post(route('shm.memorandums.approve', $memo->id), ['notes' => 'BM illegal approval attempt'])
            ->assertForbidden();
    }

    public function test_scope_b_branch_manager_cannot_access_accounting_management(): void
    {
        $this->actingAs($this->branchManager)->get(route('shm.coa.index'))->assertForbidden();
        $this->actingAs($this->branchManager)->get(route('shm.accounting-import.index'))->assertForbidden();
        $this->actingAs($this->branchManager)->get(route('shm.accounting-mapping.index'))->assertForbidden();
    }

    // =========================================================================
    // Scope C: Presiden Direktur Makro Strategic & Decision Authority
    // =========================================================================

    public function test_scope_c_director_has_exclusive_approval_decide_permission(): void
    {
        $this->assertTrue($this->direktur->hasPermission('shm.approval.decide', EntityContext::Makro));
        $this->assertTrue($this->direktur->hasPermission('shm.memorandum.approve', EntityContext::Makro));
        $this->assertTrue($this->direktur->hasPermission('shm.memorandum.reject', EntityContext::Makro));

        $this->actingAs($this->direktur)->get(route('shm.approvals.index'))->assertOk();
        $this->actingAs($this->direktur)->get(route('shm.customers.index'))->assertOk();
        $this->actingAs($this->direktur)->get(route('shm.collection.index'))->assertOk();
        $this->actingAs($this->direktur)->get(route('shm.balance-sheet.index'))->assertOk();
    }

    public function test_scope_c_director_can_approve_application_and_it_is_terminal(): void
    {
        $customer = ShmCustomer::factory()->create();
        $app = ShmApplication::create([
            'application_number' => 'APP-DIR-TEST-'.uniqid(),
            'shm_customer_id' => $customer->id,
            'marketing_id' => $this->branchManager->id,
            'type' => 'shgb',
            'status' => 'approval',
        ]);

        $response = $this->actingAs($this->direktur)
            ->post(route('shm.applications.approve', $app->id), ['notes' => 'Disetujui Direktur']);

        $response->assertSessionHas('success');
        $app->refresh();
        $this->assertSame(ShmApplicationStatus::Approved, $app->status);
        $this->assertDatabaseHas('shm_approvals', [
            'shm_application_id' => $app->id,
            'decided_by' => $this->direktur->id,
            'status' => 'approved',
        ]);

        // Terminal state: Tidak dapat diapprove ulang (validasi transisi terpicu di form request / session errors)
        $this->actingAs($this->direktur)
            ->post(route('shm.applications.approve', $app->id), ['notes' => 'Coba approve lagi'])
            ->assertSessionHasErrors();
    }

    public function test_scope_c_director_cannot_access_finance_operational_actions(): void
    {
        $this->actingAs($this->direktur)->get(route('shm.coa.index'))->assertForbidden();
        $this->actingAs($this->direktur)->get(route('shm.accounting-import.index'))->assertForbidden();
        $this->actingAs($this->direktur)->get(route('shm.accounting-mapping.index'))->assertForbidden();
    }

    // =========================================================================
    // Scope D: Finance Operational Makro Financial & Accounting Operations
    // =========================================================================

    public function test_scope_d_finance_can_access_accounting_and_payment_modules(): void
    {
        $this->actingAs($this->finance)->get(route('shm.coa.index'))->assertOk();
        $this->actingAs($this->finance)->get(route('shm.accounting-import.index'))->assertOk();
        $this->actingAs($this->finance)->get(route('shm.accounting-mapping.index'))->assertOk();
        $this->actingAs($this->finance)->get(route('shm.product-accounting.index'))->assertOk();
        $this->actingAs($this->finance)->get(route('shm.daily-journal.index'))->assertOk();
        $this->actingAs($this->finance)->get(route('shm.trial-balance.index'))->assertOk();
        $this->actingAs($this->finance)->get(route('shm.balance-sheet.index'))->assertOk();
        $this->actingAs($this->finance)->get(route('shm.period-profit-loss.index'))->assertOk();
        $this->actingAs($this->finance)->get(route('shm.cumulative-profit-loss.index'))->assertOk();

        // Financial loan transactions
        $this->actingAs($this->finance)->get(route('shm.loans.payments.index'))->assertOk();
        $this->actingAs($this->finance)->get(route('shm.loans.pelunasan.index'))->assertOk();
    }

    public function test_scope_d_finance_cannot_perform_director_approval(): void
    {
        $this->assertFalse($this->finance->hasPermission('shm.approval.decide', EntityContext::Makro));
        $this->actingAs($this->finance)->get(route('shm.approvals.index'))->assertForbidden();
    }

    public function test_scope_d_finance_cannot_access_agent_master_or_credit_checking(): void
    {
        $this->actingAs($this->finance)->get(route('shm.agents.index'))->assertForbidden();
        $this->actingAs($this->finance)->get(route('shm.credit-checks.index'))->assertForbidden();
    }

    // =========================================================================
    // Scope E & F: Negative Authorization, IDOR & Superadmin Protection
    // =========================================================================

    public function test_scope_e_f_operational_roles_cannot_access_shared_user_settings(): void
    {
        $this->actingAs($this->direktur)->get(route('pengaturan.roles.index'))->assertForbidden();
        $this->actingAs($this->branchManager)->get(route('pengaturan.roles.index'))->assertForbidden();
        $this->actingAs($this->finance)->get(route('pengaturan.roles.index'))->assertForbidden();

        $this->actingAs($this->direktur)->get(route('pengaturan.users.index'))->assertForbidden();
        $this->actingAs($this->branchManager)->get(route('pengaturan.users.index'))->assertForbidden();
        $this->actingAs($this->finance)->get(route('pengaturan.users.index'))->assertForbidden();
    }
}
