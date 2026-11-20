<?php

namespace Tests\Feature;

use App\Enums\ShmCreditCheckStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ShmApplication;
use App\Models\ShmCreditCheck;
use App\Models\ShmCreditCheckException;
use App\Models\ShmCustomer;
use App\Models\ShmMemorandum;
use App\Models\ShmMemorandumSubmission;
use App\Models\User;
use App\Services\Shm\ShmCreditCheckExceptionService;
use App\Services\Shm\ShmMemorandumApprovalService;
use App\Services\ShmWorkflowService;
use Database\Seeders\MakroPhysicalRoleSeeder;
use Database\Seeders\MakroSystemRoleSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CreditCheckingAuthorizationAndIntegrityVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $marketing;

    private User $marketingOther;

    private User $branchManager;

    private User $direkturUtama;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(MakroPhysicalRoleSeeder::class);
        $this->seed(MakroSystemRoleSeeder::class);

        $marketingRole = Role::where('name', 'marketing_makro')->first() ?? Role::where('name', 'marketing')->firstOrFail();
        $bmRole = Role::where('name', 'branch_manager_makro')->first() ?? Role::where('name', 'branch_manager')->firstOrFail();
        $dirutRole = Role::where('name', 'direktur_utama_makro')->first() ?? Role::where('name', 'direktur_utama')->firstOrFail();
        $superadminRole = Role::where('name', 'superadmin')->firstOrFail();

        $this->marketing = User::factory()->create(['name' => 'Marketing User']);
        $this->marketing->roles()->attach($marketingRole);

        $this->marketingOther = User::factory()->create(['name' => 'Other Marketing User']);
        $this->marketingOther->roles()->attach($marketingRole);

        $this->branchManager = User::factory()->create(['name' => 'Branch Manager User']);
        $this->branchManager->roles()->attach($bmRole);

        $this->direkturUtama = User::factory()->create(['name' => 'President Director User']);
        $this->direkturUtama->roles()->attach($dirutRole);

        $this->superadmin = User::factory()->create(['name' => 'Superadmin Tech User']);
        $this->superadmin->roles()->attach($superadminRole);
    }

    private function createCreditCheck(string $status = 'drafted', ?User $owner = null): ShmCreditCheck
    {
        $owner = $owner ?? $this->marketing;
        $customer = ShmCustomer::factory()->create();

        $app = ShmApplication::create([
            'application_number' => 'APP-09A-'.uniqid(),
            'shm_customer_id' => $customer->id,
            'marketing_id' => $owner->id,
            'type' => 'shgb',
            'status' => 'credit_checking',
        ]);

        return ShmCreditCheck::create([
            'shm_application_id' => $app->id,
            'status' => $status,
            'filled_by' => $owner->id,
            'started_at' => now(),
        ]);
    }

    // =========================================================================
    // Scope A: Strict Authorization Alignment
    // =========================================================================

    public function test_superadmin_cannot_perform_normal_workflow_transitions(): void
    {
        $draftCheck = $this->createCreditCheck('drafted');
        $approveCheck = $this->createCreditCheck('approve');
        $rejectedCheck = $this->createCreditCheck('rejected');

        // Superadmin mencoba Drafted -> Approve
        $resApprove = $this->actingAs($this->superadmin)
            ->postJson(route('shm.credit-checks.transition', $draftCheck->id), [
                'status' => 'approve',
            ]);
        $resApprove->assertStatus(422)->assertJsonValidationErrors(['status']);

        // Superadmin mencoba Drafted -> Rejected
        $resReject = $this->actingAs($this->superadmin)
            ->postJson(route('shm.credit-checks.transition', $draftCheck->id), [
                'status' => 'rejected',
            ]);
        $resReject->assertStatus(422)->assertJsonValidationErrors(['status']);

        // Superadmin mencoba Approve -> Completed
        $resComplete = $this->actingAs($this->superadmin)
            ->postJson(route('shm.credit-checks.transition', $approveCheck->id), [
                'status' => 'completed',
            ]);
        $resComplete->assertStatus(422)->assertJsonValidationErrors(['status']);

        // Superadmin mencoba Rejected -> Drafted
        $resDraft = $this->actingAs($this->superadmin)
            ->postJson(route('shm.credit-checks.transition', $rejectedCheck->id), [
                'status' => 'drafted',
            ]);
        $resDraft->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_direktur_utama_cannot_perform_normal_workflow_transitions(): void
    {
        $draftCheck = $this->createCreditCheck('drafted');
        $approveCheck = $this->createCreditCheck('approve');

        // Direktur Utama mencoba Drafted -> Approve
        $resApprove = $this->actingAs($this->direkturUtama)
            ->postJson(route('shm.credit-checks.transition', $draftCheck->id), [
                'status' => 'approve',
            ]);
        $resApprove->assertStatus(422)->assertJsonValidationErrors(['status']);

        // Direktur Utama mencoba Approve -> Completed
        $resComplete = $this->actingAs($this->direkturUtama)
            ->postJson(route('shm.credit-checks.transition', $approveCheck->id), [
                'status' => 'completed',
            ]);
        $resComplete->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_branch_manager_can_execute_all_allowed_normal_transitions(): void
    {
        $check = $this->createCreditCheck('drafted');

        // BM: Drafted -> Approve
        $this->actingAs($this->branchManager)
            ->postJson(route('shm.credit-checks.transition', $check->id), ['status' => 'approve'])
            ->assertOk();
        $this->assertSame('approve', $check->fresh()->status->value);

        // BM: Approve -> Completed
        $this->actingAs($this->branchManager)
            ->postJson(route('shm.credit-checks.transition', $check->id), ['status' => 'completed'])
            ->assertOk();
        $this->assertSame('completed', $check->fresh()->status->value);
    }

    public function test_marketing_can_only_transition_rejected_to_drafted_for_owned_application(): void
    {
        $checkOwned = $this->createCreditCheck('rejected', $this->marketing);
        $checkOther = $this->createCreditCheck('rejected', $this->marketingOther);

        // Marketing dapat mengubah pengajuan miliknya dari rejected -> drafted
        $this->actingAs($this->marketing)
            ->postJson(route('shm.credit-checks.transition', $checkOwned->id), ['status' => 'drafted'])
            ->assertOk();
        $this->assertSame('drafted', $checkOwned->fresh()->status->value);

        // Marketing dilarang mengubah pengajuan milik orang lain
        $this->actingAs($this->marketing)
            ->postJson(route('shm.credit-checks.transition', $checkOther->id), ['status' => 'drafted'])
            ->assertForbidden();

        // Marketing dilarang self-approve
        $draftCheck = $this->createCreditCheck('drafted', $this->marketing);
        $this->actingAs($this->marketing)
            ->postJson(route('shm.credit-checks.transition', $draftCheck->id), ['status' => 'approve'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_available_transitions_prop_in_index_excludes_superadmin_and_direktur_utama(): void
    {
        $draftCheck = $this->createCreditCheck('drafted', $this->marketing);

        // Index dilihat oleh Superadmin -> available_transitions kosong
        $resSuperadmin = $this->actingAs($this->superadmin)->get(route('shm.credit-checks.index'));
        $resSuperadmin->assertOk();
        $rowsSuperadmin = collect($resSuperadmin->original->getData()['page']['props']['credit_checks']['data']);
        $rowSuperadmin = $rowsSuperadmin->firstWhere('credit_check_id', $draftCheck->id);
        $this->assertEmpty($rowSuperadmin['available_transitions']);

        // Direktur Utama secara default tidak memiliki shm.credit_checking.view sehingga diblokir middleware
        $resDirut = $this->actingAs($this->direkturUtama)->get(route('shm.credit-checks.index'));
        $resDirut->assertForbidden();

        // Jika Direktur Utama diberi izin view, available_transitions tetap kosong
        $viewPerm = Permission::where('name', 'shm.credit_checking.view')->firstOrFail();
        $this->direkturUtama->roles()->first()->permissions()->attach($viewPerm);

        $resDirutWithView = $this->actingAs($this->direkturUtama)->get(route('shm.credit-checks.index'));
        $resDirutWithView->assertOk();
        $rowsDirut = collect($resDirutWithView->original->getData()['page']['props']['credit_checks']['data']);
        $rowDirut = $rowsDirut->firstWhere('credit_check_id', $draftCheck->id);
        $this->assertEmpty($rowDirut['available_transitions']);

        // Index dilihat oleh Branch Manager -> available_transitions berisi approve & rejected
        $resBm = $this->actingAs($this->branchManager)->get(route('shm.credit-checks.index'));
        $resBm->assertOk();
        $rowsBm = collect($resBm->original->getData()['page']['props']['credit_checks']['data']);
        $rowBm = $rowsBm->firstWhere('credit_check_id', $draftCheck->id);
        $this->assertEqualsCanonicalizing(['approve', 'rejected'], collect($rowBm['available_transitions'])->pluck('value')->all());
    }

    // =========================================================================
    // Scope B: Memorandum Gate & Completion Bypass Verification
    // =========================================================================

    public function test_normal_memorandum_gate_blocks_drafted_approve_and_rejected_statuses(): void
    {
        $workflow = app(ShmWorkflowService::class);

        // 1. Status Drafted -> Blocked
        $appDrafted = $this->createCreditCheck('drafted')->application;
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('belum selesai (Completed)');
        $workflow->startMemorandum($appDrafted, $this->marketing->id);
    }

    public function test_normal_memorandum_gate_blocks_approve_status(): void
    {
        $workflow = app(ShmWorkflowService::class);

        // 2. Status Approve -> Blocked
        $appApprove = $this->createCreditCheck('approve')->application;
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('belum selesai (Completed)');
        $workflow->startMemorandum($appApprove, $this->marketing->id);
    }

    public function test_normal_memorandum_gate_blocks_rejected_status(): void
    {
        $workflow = app(ShmWorkflowService::class);

        // 3. Status Rejected -> Blocked
        $appRejected = $this->createCreditCheck('rejected')->application;
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('belum selesai (Completed)');
        $workflow->startMemorandum($appRejected, $this->marketing->id);
    }

    public function test_normal_memorandum_gate_allows_completed_status(): void
    {
        $workflow = app(ShmWorkflowService::class);

        $appCompleted = $this->createCreditCheck('completed')->application;
        $res = $workflow->startMemorandum($appCompleted, $this->marketing->id);

        $this->assertInstanceOf(ShmApplication::class, $res);
        $this->assertSame('memorandum', $res->status->value);
    }

    public function test_direktur_utama_and_superadmin_can_authorize_completion_bypass_with_accurate_actor_audit(): void
    {
        $service = app(ShmCreditCheckExceptionService::class);

        // 1. Direktur Utama (Business Authority)
        $appDirut = $this->createCreditCheck('drafted')->application;
        $expDirut = $service->authorizeException(
            $appDirut,
            ShmCreditCheckException::TYPE_COMPLETION_BYPASS,
            $this->direkturUtama,
            'Justifikasi bisnis mendesak Direktur Utama untuk pembiayaan prioritas.',
            'Dispensasi Completion Bypass oleh Direktur Utama',
            'Credit checking drafted'
        );

        $this->assertSame($this->direkturUtama->id, $expDirut->authorized_by);
        $this->assertSame($this->direkturUtama->name, $expDirut->authorized_by_name);
        $this->assertSame(ShmCreditCheckException::STATUS_AUTHORIZED, $expDirut->status);
        $this->assertTrue($expDirut->isAvailable());

        // 2. Superadmin (Technical Troubleshooting Access)
        $appSuper = $this->createCreditCheck('drafted')->application;
        $expSuper = $service->authorizeException(
            $appSuper,
            ShmCreditCheckException::TYPE_COMPLETION_BYPASS,
            $this->superadmin,
            'Justifikasi teknis troubleshooting integrasi migrasi staging.',
            'Dispensasi teknis bypass completion oleh Superadmin',
            'Credit checking drafted'
        );

        $this->assertSame($this->superadmin->id, $expSuper->authorized_by);
        $this->assertSame($this->superadmin->name, $expSuper->authorized_by_name);
        $this->assertSame(ShmCreditCheckException::STATUS_AUTHORIZED, $expSuper->status);
        $this->assertTrue($expSuper->isAvailable());
    }

    public function test_branch_manager_and_marketing_cannot_authorize_completion_bypass(): void
    {
        $service = app(ShmCreditCheckExceptionService::class);
        $app = $this->createCreditCheck('drafted')->application;

        // Branch Manager ditolak (403)
        try {
            $service->authorizeException(
                $app,
                ShmCreditCheckException::TYPE_COMPLETION_BYPASS,
                $this->branchManager,
                'Percobaan bypass oleh Branch Manager.',
                'Ringkasan keterbukaan resmi BM',
                'Credit checking belum selesai'
            );
            $this->fail('Expected HttpException 403 not thrown for Branch Manager');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        // Marketing ditolak (403)
        try {
            $service->authorizeException(
                $app,
                ShmCreditCheckException::TYPE_COMPLETION_BYPASS,
                $this->marketing,
                'Percobaan bypass oleh Marketing AO.',
                'Ringkasan keterbukaan resmi Marketing',
                'Credit checking belum selesai'
            );
            $this->fail('Expected HttpException 403 not thrown for Marketing');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_completion_bypass_does_not_mutate_credit_check_status_to_completed(): void
    {
        $check = $this->createCreditCheck('drafted');
        $app = $check->application;

        $service = app(ShmCreditCheckExceptionService::class);
        $service->authorizeException(
            $app,
            ShmCreditCheckException::TYPE_COMPLETION_BYPASS,
            $this->direkturUtama,
            'Justifikasi bisnis mendesak Direktur Utama untuk pembiayaan prioritas.',
            'Dispensasi Completion Bypass oleh Direktur Utama',
            'Credit checking drafted'
        );

        // Lewati Memorandum Gate menggunakan bypass
        $workflow = app(ShmWorkflowService::class);
        $workflow->startMemorandum($app, $this->marketing->id);

        // Verifikasi bahwa credit check status TIDAK berubah secara diam-diam menjadi completed!
        $this->assertSame('drafted', $check->fresh()->status->value);
    }

    public function test_completion_bypass_lifecycle_and_14_day_expiry(): void
    {
        $check = $this->createCreditCheck('drafted');
        $app = $check->application;

        $service = app(ShmCreditCheckExceptionService::class);
        $exp = $service->authorizeException(
            $app,
            ShmCreditCheckException::TYPE_COMPLETION_BYPASS,
            $this->direkturUtama,
            'Justifikasi bisnis mendesak Direktur Utama untuk pembiayaan prioritas.',
            'Dispensasi Completion Bypass oleh Direktur Utama',
            'Credit checking drafted'
        );

        // 1. Berlaku saat masih baru
        $this->assertTrue($exp->isAvailable());

        // 2. Tidak berlaku jika sudah melewati 14 hari
        $exp->authorized_at = now()->subDays(15);
        $exp->save();
        $this->assertFalse($exp->fresh()->isAvailable());

        // Kembalikan tanggal berlaku
        $exp->authorized_at = now();
        $exp->save();
        $this->assertTrue($exp->fresh()->isAvailable());

        // 3. Dicabut (Revoked) -> tidak dapat dikonsumsi
        $service->revokeException($exp, $this->direkturUtama, 'Dibatalkan karena nasabah batal mengajukan.');
        $this->assertSame(ShmCreditCheckException::STATUS_REVOKED, $exp->fresh()->status);
        $this->assertFalse($exp->fresh()->isAvailable());
    }

    public function test_memorandum_submission_consumes_bypass_atomically_and_blocks_reuse(): void
    {
        $check = $this->createCreditCheck('drafted');
        $app = $check->application;

        $expService = app(ShmCreditCheckExceptionService::class);
        $exp = $expService->authorizeException(
            $app,
            ShmCreditCheckException::TYPE_COMPLETION_BYPASS,
            $this->direkturUtama,
            'Justifikasi bisnis mendesak Direktur Utama untuk pembiayaan prioritas.',
            'Dispensasi Completion Bypass oleh Direktur Utama',
            'Credit checking drafted'
        );

        $workflow = app(ShmWorkflowService::class);
        $workflow->startMemorandum($app, $this->marketing->id);

        $memo = ShmMemorandum::create([
            'shm_application_id' => $app->id,
            'created_by' => $this->marketing->id,
            'status' => ShmMemorandum::STATUS_DRAFT,
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
        ]);

        $approvalService = app(ShmMemorandumApprovalService::class);
        $submitted = $approvalService->submit($memo, $this->marketing, 'Catatan submit');

        $this->assertSame(ShmMemorandum::STATUS_SUBMITTED, $submitted->status);
        $this->assertSame(ShmCreditCheckException::STATUS_CONSUMED, $exp->fresh()->status);
        $this->assertNotNull($exp->fresh()->consumed_at);
        $this->assertSame($memo->id, $exp->fresh()->consumed_memorandum_id);

        // Percobaan reuse konsumsi kedua harus gagal
        $this->expectException(ValidationException::class);
        $expService->consumeException($app, ShmCreditCheckException::TYPE_COMPLETION_BYPASS, $memo);
    }

    // =========================================================================
    // Scope C: Rechecking Architecture & Historical Integrity
    // =========================================================================

    public function test_rechecking_architecture_preserves_historical_assessment_and_frozen_snapshots(): void
    {
        // 1. Initial assessment Completed
        $check1 = $this->createCreditCheck('completed');
        $app = $check1->application;
        $check1->result = 'Hasil assessment awal: layak dengan syarat.';
        $check1->save();

        $workflow = app(ShmWorkflowService::class);
        $workflow->startMemorandum($app, $this->marketing->id);

        $memo = ShmMemorandum::create([
            'shm_application_id' => $app->id,
            'created_by' => $this->marketing->id,
            'status' => ShmMemorandum::STATUS_DRAFT,
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
        ]);

        $approvalService = app(ShmMemorandumApprovalService::class);
        $memo = $approvalService->submit($memo, $this->marketing, 'First submission');

        // Snapshot beku pada submission revisi 1
        $submission1 = ShmMemorandumSubmission::where('shm_memorandum_id', $memo->id)->where('revision_number', 1)->firstOrFail();
        $this->assertSame($check1->id, $submission1->credit_check_snapshot['source_credit_check_id']);
        $this->assertSame('completed', $submission1->credit_check_snapshot['credit_check']['status']);

        // 2. Verifikasi completed assessment tidak dapat diubah lagi melalui normal workflow
        try {
            $workflow->transitionCreditCheck($check1, ShmCreditCheckStatus::Drafted, $this->branchManager);
            $this->fail('Expected ValidationException not thrown for completed credit check transition');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        // 3. Verifikasi integritas snapshot submission revisi 1 tetap tidak berubah (immutable)
        $this->assertSame($check1->id, $submission1->fresh()->credit_check_snapshot['source_credit_check_id']);
        $this->assertSame('Hasil assessment awal: layak dengan syarat.', $submission1->fresh()->credit_check_snapshot['credit_check']['result_summary']);
    }
}
