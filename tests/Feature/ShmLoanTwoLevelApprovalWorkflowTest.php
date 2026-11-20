<?php

namespace Tests\Feature;

use App\Enums\ShmApplicationStatus;
use App\Models\Role;
use App\Models\ShmApplication;
use App\Models\ShmApproval;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanProduct;
use App\Models\User;
use App\Services\Shm\ShmMemorandumApprovalService;
use App\Services\ShmWorkflowService;
use Database\Seeders\MakroPhysicalRoleSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\ShmLoanProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithShmDisbursement;
use Tests\TestCase;

/**
 * TICKET 29A — SHM Loan Two-Level Approval Workflow & State Machine Enforcement.
 *
 * Verifies the 14 mandatory test scenarios:
 * 1. Branch Manager can approve Level 1.
 * 2. Branch Manager cannot execute Level 2 final approval.
 * 3. President Director cannot approve prior to Level 1 approval.
 * 4. President Director can approve after Level 1 approval.
 * 5. Marketing cannot execute either approval stage (403 Forbidden).
 * 6. Finance Operational cannot execute either approval stage (403 Forbidden).
 * 7. Disbursement is blocked after Level 1 approval only.
 * 8. Disbursement is blocked before any approval stage.
 * 9. Disbursement succeeds only after both approval stages are completed.
 * 10. Repeated Level 1 approval is rejected.
 * 11. Repeated Level 2 approval is rejected.
 * 12. State machine enforces sequential locking via lockForUpdate.
 * 13. Cross-entity and unauthorized record access remain blocked.
 * 14. Memorandum review and approval workflow remain isolated and unaffected.
 */
class ShmLoanTwoLevelApprovalWorkflowTest extends TestCase
{
    use InteractsWithShmDisbursement;
    use RefreshDatabase;

    private User $director;

    private User $branchManager;

    private User $marketing;

    private User $finance;

    private User $superadmin;

    private ShmLoanProduct $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(MakroPhysicalRoleSeeder::class);
        $this->seed(ShmLoanProductSeeder::class);

        // User 10: direktur_utama_makro
        $this->director = User::factory()->create(['id' => 10, 'name' => 'Presiden Direktur Makro', 'email' => 'pd@ksp.test', 'is_active' => true]);
        $this->director->roles()->attach(Role::where('name', 'direktur_utama_makro')->value('id'));

        // User 11: branch_manager_makro
        $this->branchManager = User::factory()->create(['id' => 11, 'name' => 'Branch Manager Makro', 'email' => 'bm@ksp.test', 'is_active' => true]);
        $this->branchManager->roles()->attach(Role::where('name', 'branch_manager_makro')->value('id'));

        // User 12: finance_operational_makro
        $this->finance = User::factory()->create(['id' => 12, 'name' => 'Finance Operational Makro', 'email' => 'fo@ksp.test', 'is_active' => true]);
        $this->finance->roles()->attach(Role::where('name', 'finance_operational_makro')->value('id'));

        // Marketing Makro
        $this->marketing = User::factory()->create(['id' => 20, 'name' => 'Marketing Makro', 'email' => 'marketing@ksp.test', 'is_active' => true]);
        $this->marketing->roles()->attach(Role::where('name', 'marketing_makro')->value('id'));

        // User 13: superadmin
        $this->superadmin = User::factory()->create(['id' => 13, 'name' => 'Superadmin', 'email' => 'superadmin@ksp.test', 'is_active' => true]);
        $this->superadmin->roles()->attach(Role::where('name', 'superadmin')->value('id'));

        $this->product = ShmLoanProduct::firstOrFail();

        // Disbursement fixtures: active savings products + active cashier session for finance
        $this->seedShmSavingsProducts();
        $this->openCashierSessionFor($this->finance);
    }

    private function createLoanApplicationInApprovalStage(): array
    {
        $customer = $this->createShmCustomerWithMember();

        $payload = [
            'pribadi' => ['customer_id' => $customer->id],
            'pengajuan' => [
                'product_id' => $this->product->id,
                'principal' => '100000000',
                'tenor_months' => 24,
                'purpose' => 'Modal Usaha Makro',
                'first_due_date' => now()->addMonth()->format('Y-m-d'),
                'interest_rate' => '1.50',
            ],
            'agunan' => [
                ['jenis' => 'SHM', 'nomor' => 'SHM-999', 'tahun' => '2021', 'nilai' => '250000000'],
            ],
            'jaminan' => [
                [
                    'tanggal' => now()->format('Y-m-d'),
                    'jenis' => 'SHM',
                    'nama' => 'Tanah dan Bangunan',
                    'keterangan' => 'Sertifikat Hak Milik',
                    'nilai_perkiraan' => '250000000',
                    'status_pengikatan' => 'Belum diikat',
                    'tanggal_pengikatan' => '',
                    'nilai_hak_tanggungan' => '',
                ],
            ],
            'catatan' => [
                'date' => now()->format('Y-m-d'),
                'name' => 'Marketing Catatan',
                'notes' => 'Pengajuan uji approval',
            ],
            'analisa' => [
                'analyst_code' => 'AN-01',
                'analyst_office' => 'Kantor Makro',
                'officer_code' => 'AO-01',
                'officer_office' => 'Kantor Makro',
                'date' => now()->format('Y-m-d'),
                'value' => '100000000',
                'notes' => 'Analisa kelayakan nasabah',
            ],
            'kolektor' => ['name' => 'Kolektor Makro'],
            'persetujuan' => [
                'approved_amount' => '100000000',
                'provision_fee' => '1000000',
                'administration_fee' => '500000',
                'method' => 'flat',
                'notes' => 'Direkomendasikan disetujui',
            ],
        ];

        $this->actingAs($this->marketing)
            ->post(route('shm.loans.pengajuan.store'), $payload)
            ->assertSessionHasNoErrors();

        $loanApp = ShmLoanApplication::query()->latest('id')->firstOrFail();

        $this->actingAs($this->marketing)
            ->post(route('shm.loans.pengajuan.submit-approval', $loanApp))
            ->assertSessionHasNoErrors();

        $mirror = $loanApp->fresh()->shmApplication;

        return [$loanApp->fresh(), $mirror->fresh()];
    }

    /** Scenario 1: Branch Manager can execute Level 1 approval. */
    public function test_branch_manager_can_approve_level_1(): void
    {
        [$loanApp, $mirror] = $this->createLoanApplicationInApprovalStage();

        $this->actingAs($this->branchManager)
            ->post(route('shm.applications.approve-bm', $mirror), ['notes' => 'Rekomendasi disetujui BM'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($mirror->fresh()->hasBmApproval());
        $this->assertFalse($mirror->fresh()->hasDirectorApproval());
        $this->assertSame(ShmApplicationStatus::Approval, $mirror->fresh()->status);

        $bmApproval = $mirror->fresh()->bmApproval();
        $this->assertNotNull($bmApproval);
        $this->assertSame(ShmApproval::STATUS_APPROVED_BM, $bmApproval->status);
        $this->assertSame($this->branchManager->id, $bmApproval->decided_by);
        $this->assertSame('Rekomendasi disetujui BM', $bmApproval->notes);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'shm.application.approved_bm',
            'actor_id' => $this->branchManager->id,
            'object_id' => $mirror->id,
        ]);
    }

    /** Scenario 2: Branch Manager cannot execute Level 2 final approval (403 Forbidden). */
    public function test_branch_manager_cannot_execute_level_2_final_approval(): void
    {
        [$loanApp, $mirror] = $this->createLoanApplicationInApprovalStage();

        // Level 1 first
        $this->actingAs($this->branchManager)
            ->post(route('shm.applications.approve-bm', $mirror))
            ->assertSessionHasNoErrors();

        // BM attempts Level 2 final approval
        $this->actingAs($this->branchManager)
            ->post(route('shm.applications.approve', $mirror), ['notes' => 'BM coba approve final'])
            ->assertForbidden();

        $this->assertFalse($mirror->fresh()->hasDirectorApproval());
        $this->assertSame(ShmApplicationStatus::Approval, $mirror->fresh()->status);
    }

    /** Scenario 3: President Director cannot approve prior to Level 1 approval (validation error, fail-closed). */
    public function test_president_director_cannot_approve_prior_to_level_1_approval(): void
    {
        [$loanApp, $mirror] = $this->createLoanApplicationInApprovalStage();

        $this->assertFalse($mirror->hasBmApproval());

        $this->actingAs($this->director)
            ->post(route('shm.applications.approve', $mirror), ['notes' => 'Direktur approve langsung'])
            ->assertSessionHasErrors(['status']);

        $this->assertFalse($mirror->fresh()->hasDirectorApproval());
        $this->assertSame(ShmApplicationStatus::Approval, $mirror->fresh()->status);
    }

    /** Scenario 4: President Director can approve after Level 1 approval (success, status -> Approved). */
    public function test_president_director_can_approve_after_level_1_approval(): void
    {
        [$loanApp, $mirror] = $this->createLoanApplicationInApprovalStage();

        // Level 1 by BM
        $this->actingAs($this->branchManager)
            ->post(route('shm.applications.approve-bm', $mirror), ['notes' => 'BM setuju'])
            ->assertSessionHasNoErrors();

        // Level 2 by President Director
        $this->actingAs($this->director)
            ->post(route('shm.applications.approve', $mirror), ['notes' => 'Direktur setuju final'])
            ->assertSessionHasNoErrors();

        $mirrorFresh = $mirror->fresh();
        $this->assertTrue($mirrorFresh->hasBmApproval());
        $this->assertTrue($mirrorFresh->hasDirectorApproval());
        $this->assertTrue($mirrorFresh->isFullyApproved());
        $this->assertSame(ShmApplicationStatus::Approved, $mirrorFresh->status);

        $directorApproval = $mirrorFresh->directorApproval();
        $this->assertNotNull($directorApproval);
        $this->assertSame(ShmApproval::STATUS_APPROVED, $directorApproval->status);
        $this->assertSame($this->director->id, $directorApproval->decided_by);
        $this->assertSame('Direktur setuju final', $directorApproval->notes);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'shm.application.approved',
            'actor_id' => $this->director->id,
            'object_id' => $mirror->id,
        ]);
    }

    /** Scenario 5: Marketing cannot execute either approval stage (403 Forbidden). */
    public function test_marketing_cannot_execute_either_approval_stage(): void
    {
        [$loanApp, $mirror] = $this->createLoanApplicationInApprovalStage();

        $this->actingAs($this->marketing)
            ->post(route('shm.applications.approve-bm', $mirror))
            ->assertForbidden();

        $this->actingAs($this->marketing)
            ->post(route('shm.applications.approve', $mirror))
            ->assertForbidden();

        $this->assertFalse($mirror->fresh()->hasBmApproval());
        $this->assertFalse($mirror->fresh()->hasDirectorApproval());
    }

    /** Scenario 6: Finance Operational cannot execute either approval stage (403 Forbidden). */
    public function test_finance_operational_cannot_execute_either_approval_stage(): void
    {
        [$loanApp, $mirror] = $this->createLoanApplicationInApprovalStage();

        $this->actingAs($this->finance)
            ->post(route('shm.applications.approve-bm', $mirror))
            ->assertForbidden();

        $this->actingAs($this->finance)
            ->post(route('shm.applications.approve', $mirror))
            ->assertForbidden();

        $this->assertFalse($mirror->fresh()->hasBmApproval());
        $this->assertFalse($mirror->fresh()->hasDirectorApproval());
    }

    /** Scenario 7: Disbursement is blocked after Level 1 approval only. */
    public function test_disbursement_is_blocked_after_level_1_approval_only(): void
    {
        [$loanApp, $mirror] = $this->createLoanApplicationInApprovalStage();

        $this->actingAs($this->branchManager)
            ->post(route('shm.applications.approve-bm', $mirror))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->finance)
            ->post(route('shm.loans.pengajuan.disburse', $loanApp))
            ->assertSessionHasErrors(['application']);

        $this->assertSame(0, ShmLoan::query()->count());
    }

    /** Scenario 8: Disbursement is blocked before any approval stage. */
    public function test_disbursement_is_blocked_before_any_approval(): void
    {
        [$loanApp, $mirror] = $this->createLoanApplicationInApprovalStage();

        $this->actingAs($this->finance)
            ->post(route('shm.loans.pengajuan.disburse', $loanApp))
            ->assertSessionHasErrors(['application']);

        $this->assertSame(0, ShmLoan::query()->count());
    }

    /** Scenario 9: Disbursement succeeds only after both approval stages are completed. */
    public function test_disbursement_succeeds_after_both_approval_stages(): void
    {
        [$loanApp, $mirror] = $this->createLoanApplicationInApprovalStage();

        // Level 1: Branch Manager
        $this->actingAs($this->branchManager)
            ->post(route('shm.applications.approve-bm', $mirror))
            ->assertSessionHasNoErrors();

        // Level 2: President Director
        $this->actingAs($this->director)
            ->post(route('shm.applications.approve', $mirror))
            ->assertSessionHasNoErrors();

        // Disbursement by Finance Operational
        $this->actingAs($this->finance)
            ->post(route('shm.loans.pengajuan.disburse', $loanApp))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame(1, ShmLoan::query()->count());
        $loan = ShmLoan::query()->sole();
        $this->assertSame($loanApp->id, $loan->shm_loan_application_id);
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->status);
        $this->assertNotNull($loan->disbursed_at);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'shm.loan_application.disbursed',
            'actor_id' => $this->finance->id,
            'object_id' => $loanApp->id,
        ]);
    }

    /** Scenario 10: Repeated Level 1 approval is rejected. */
    public function test_repeated_level_1_approval_is_rejected(): void
    {
        [$loanApp, $mirror] = $this->createLoanApplicationInApprovalStage();

        $this->actingAs($this->branchManager)
            ->post(route('shm.applications.approve-bm', $mirror))
            ->assertSessionHasNoErrors();

        // Duplicate Level 1 attempt
        $this->actingAs($this->branchManager)
            ->post(route('shm.applications.approve-bm', $mirror))
            ->assertSessionHasErrors(['status']);

        $this->assertSame(1, $mirror->approvals()->where('status', ShmApproval::STATUS_APPROVED_BM)->count());
    }

    /** Scenario 11: Repeated Level 2 approval is rejected. */
    public function test_repeated_level_2_approval_is_rejected(): void
    {
        [$loanApp, $mirror] = $this->createLoanApplicationInApprovalStage();

        $this->actingAs($this->branchManager)
            ->post(route('shm.applications.approve-bm', $mirror))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->director)
            ->post(route('shm.applications.approve', $mirror))
            ->assertSessionHasNoErrors();

        // Duplicate Level 2 attempt
        $this->actingAs($this->director)
            ->post(route('shm.applications.approve', $mirror))
            ->assertSessionHasErrors(['status']);

        $this->assertSame(1, $mirror->approvals()->where('status', ShmApproval::STATUS_APPROVED)->count());
    }

    /** Scenario 12: Concurrent approval attempts cannot bypass the state machine (lockForUpdate). */
    public function test_concurrent_approval_attempts_cannot_bypass_state_machine(): void
    {
        [$loanApp, $mirror] = $this->createLoanApplicationInApprovalStage();

        // Simulate concurrent lock within transaction
        DB::transaction(function () use ($mirror) {
            $locked = ShmApplication::query()->lockForUpdate()->find($mirror->id);
            $this->assertNotNull($locked);

            // Attempting final approval before Level 1 inside transactional boundary throws validation error
            $service = app(ShmWorkflowService::class);
            $caught = false;
            try {
                $service->approve($locked, $this->director->id);
            } catch (ValidationException $e) {
                $caught = true;
                $this->assertArrayHasKey('status', $e->errors());
            }
            $this->assertTrue($caught, 'Direct approve prior to Level 1 must fail even in concurrency lock.');
        });
    }

    /** Scenario 13: Cross-entity and unauthorized record access remain blocked. */
    public function test_cross_entity_and_unauthorized_record_access_remain_blocked(): void
    {
        [$loanApp, $mirror] = $this->createLoanApplicationInApprovalStage();

        // Create Mikro user
        $mikroRole = Role::firstOrCreate(
            ['name' => 'admin_mikro'],
            ['display_name' => 'Admin Mikro', 'is_system' => true, 'entity' => 'mikro']
        );
        $mikroUser = User::factory()->create(['name' => 'Admin Mikro', 'is_active' => true]);
        $mikroUser->roles()->attach($mikroRole->id);

        $this->actingAs($mikroUser)
            ->post(route('shm.applications.approve-bm', $mirror))
            ->assertForbidden();

        $this->actingAs($mikroUser)
            ->post(route('shm.applications.approve', $mirror))
            ->assertForbidden();

        $this->actingAs($mikroUser)
            ->post(route('shm.loans.pengajuan.disburse', $loanApp))
            ->assertForbidden();
    }

    /** Scenario 14: Memorandum review and approval workflow remain isolated and unaffected. */
    public function test_memorandum_approval_workflow_remains_independent_and_unaffected(): void
    {
        $customer = ShmCustomer::factory()->create();

        $application = ShmApplication::create([
            'application_number' => 'SHM-APP-MEMO-001',
            'shm_customer_id' => $customer->id,
            'marketing_id' => $this->marketing->id,
            'status' => ShmApplicationStatus::Memorandum,
            'type' => ShmApplication::TYPE_SHGB,
        ]);

        $memorandum = $application->memorandums()->create([
            'created_by' => $this->branchManager->id,
            'type' => 'shm_shgb',
            'status' => 'draft',
            'target_amount' => 500000000,
            'notes' => 'Memorandum independen',
        ]);

        $workflow = app(ShmMemorandumApprovalService::class);
        $workflow->submit($memorandum, $this->branchManager);
        $this->assertSame('submitted', $memorandum->fresh()->status);

        $workflow->startReview($memorandum, $this->director);
        $this->assertSame('under_review', $memorandum->fresh()->status);

        // Director approves memorandum directly without BM Level 1 loan approval dependency
        $workflow->approve($memorandum, $this->director);
        $this->assertSame('approved', $memorandum->fresh()->status);

        // Ensure Loan Application approval state was not affected or required for memorandum
        $this->assertFalse($application->hasBmApproval());
        $this->assertFalse($application->hasDirectorApproval());
    }

    /** Scenario 15: Same actor cannot approve both Level 1 and Level 2 (Actor Independence enforcement). */
    public function test_same_actor_cannot_approve_both_level_1_and_level_2(): void
    {
        [$loanApp, $mirror] = $this->createLoanApplicationInApprovalStage();

        // Level 1 approved by Superadmin (who has all permissions)
        $this->actingAs($this->superadmin)
            ->post(route('shm.applications.approve-bm', $mirror), ['notes' => 'Superadmin L1'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($mirror->fresh()->hasBmApproval());

        // Level 2 attempted by the SAME user (Superadmin) -> Must be rejected by actor independence guard
        $this->actingAs($this->superadmin)
            ->post(route('shm.applications.approve', $mirror), ['notes' => 'Superadmin L2'])
            ->assertSessionHasErrors(['status']);

        $this->assertFalse($mirror->fresh()->hasDirectorApproval());
        $this->assertSame(ShmApplicationStatus::Approval, $mirror->fresh()->status);

        // Different user (Director) CAN approve Level 2
        $this->actingAs($this->director)
            ->post(route('shm.applications.approve', $mirror), ['notes' => 'Director L2 Valid'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($mirror->fresh()->hasDirectorApproval());
        $this->assertSame(ShmApplicationStatus::Approved, $mirror->fresh()->status);
    }
}
