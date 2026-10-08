<?php

namespace Tests\Feature;

use App\Enums\ShmApplicationStatus;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ShmApplication;
use App\Models\ShmApproval;
use App\Models\ShmCustomer;
use App\Models\ShmDocument;
use App\Models\ShmMemorandum;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @todo remove this marker before commit */
class ShmApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    private User $admin;

    private User $marketing;

    private User $otherMarketing;

    private User $pengawas;

    private ShmCustomer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->superadmin = $this->userWithRole('superadmin');
        $this->admin = $this->userWithRole('admin');
        $this->marketing = $this->userWithRole('marketing');
        $this->otherMarketing = $this->userWithRole('marketing');
        $this->pengawas = $this->userWithRole('pengawas');

        $this->customer = ShmCustomer::factory()->create();
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', $roleName)->value('id'));

        return $user;
    }

    /**
     * Pengguna dengan permission eksplisit, tanpa mengubah role bawaan.
     *
     * @param  array<int, string>  $permissionNames
     */
    private function userWithPermissions(array $permissionNames): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $role = Role::query()->create([
            'name' => 'approver-'.Str::uuid(),
            'display_name' => 'Approver Test',
            'is_system' => false,
        ]);

        $role->permissions()->attach(
            Permission::query()->whereIn('name', $permissionNames)->pluck('id')
        );

        $user->roles()->attach($role->id);

        return $user;
    }

    /**
     * Buat pengajuan milik Marketing tertentu melalui HTTP agar workflow
     * pembuatan dan audit aslinya ikut berjalan.
     */
    private function createApplicationFor(User $user, array $overrides = []): ShmApplication
    {
        $this->actingAs($user)
            ->post(route('shm.applications.store'), array_merge([
                'shm_customer_id' => $this->customer->id,
                'notes' => 'Pengajuan approval test',
            ], $overrides))
            ->assertSessionHasNoErrors();

        return ShmApplication::query()->latest('id')->firstOrFail();
    }

    /**
     * Pengajuan fixture pada tahap approval, dengan memorandum kategori
     * tertentu bila diberikan.
     */
    private function approvalStageApplication(User $owner, ?string $type = null): ShmApplication
    {
        $application = $this->createApplicationFor($owner);

        // Pola fixture yang sama dengan pengujian approval Ticket 44: tahap
        // diatur langsung untuk menempatkan pengajuan pada posisi uji.
        $application->update(['status' => ShmApplicationStatus::Approval]);
        $application->refresh();

        if ($type !== null) {
            ShmMemorandum::query()->create([
                'shm_application_id' => $application->id,
                'created_by' => $owner->id,
                'type' => $type,
                'status' => ShmMemorandum::STATUS_SUBMITTED,
                'submitted_at' => now(),
            ]);
            $application->refresh();
        }

        return $application;
    }

    // -----------------------------------------------------------------
    // A. Akses modul: permission existing, bukan permission baru
    // -----------------------------------------------------------------

    public function test_admin_can_open_approval_list(): void
    {
        $application = $this->approvalStageApplication($this->marketing);

        $this->actingAs($this->admin)
            ->get(route('shm.approvals.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Shm/Approvals/Index')
                ->has('approvals.data', 1)
                ->where('approvals.data.0.application_number', $application->application_number)
            );
    }

    public function test_superadmin_follows_existing_bypass_on_approval_module(): void
    {
        $this->approvalStageApplication($this->marketing);

        $this->actingAs($this->superadmin)
            ->get(route('shm.approvals.index'))
            ->assertOk();
    }

    public function test_user_without_approval_decide_permission_is_forbidden(): void
    {
        // Marketing sengaja tidak memegang shm.approval.decide; menu pun
        // disembunyikan, tetapi permintaan langsung tetap ditolak server.
        $this->actingAs($this->marketing)
            ->get(route('shm.approvals.index'))
            ->assertForbidden();

        $this->actingAs($this->pengawas)
            ->get(route('shm.approvals.index'))
            ->assertForbidden();
    }

    public function test_user_without_permission_cannot_open_approval_detail(): void
    {
        $application = $this->approvalStageApplication($this->marketing);

        $this->actingAs($this->marketing)
            ->get(route('shm.approvals.show', $application->id))
            ->assertForbidden();

        $this->actingAs($this->pengawas)
            ->get(route('shm.approvals.show', $application->id))
            ->assertForbidden();
    }

    public function test_approval_routes_live_under_shm_prefix(): void
    {
        $index = Route::getRoutes()->getByName('shm.approvals.index');
        $show = Route::getRoutes()->getByName('shm.approvals.show');

        $this->assertNotNull($index);
        $this->assertNotNull($show);
        $this->assertSame('shm/approval', $index->uri());
        $this->assertSame('shm/approval/{application}', $show->uri());
    }

    // -----------------------------------------------------------------
    // B. Daftar approval: relevansi, filter, pencarian, pagination, cakupan
    // -----------------------------------------------------------------

    public function test_approval_list_defaults_to_pending_decisions_only(): void
    {
        $pending = $this->approvalStageApplication($this->marketing);
        $this->decidedApplication(ShmApplicationStatus::Approved);
        $this->decidedApplication(ShmApplicationStatus::Rejected);

        // Bawaan hanya menampilkan pengajuan yang menunggu keputusan;
        // hasil keputusan hanya muncul ketika diminta lewat filter.
        $this->actingAs($this->admin)
            ->get(route('shm.approvals.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('approvals.data', 1)
                ->where('approvals.data.0.application_number', $pending->application_number)
            );
    }

    public function test_approval_list_status_filter_shows_decided_applications(): void
    {
        $approved = $this->decidedApplication(ShmApplicationStatus::Approved);
        $rejected = $this->decidedApplication(ShmApplicationStatus::Rejected);
        $this->approvalStageApplication($this->marketing);

        $this->actingAs($this->admin)
            ->get(route('shm.approvals.index', ['status' => 'approved']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('approvals.data', 1)
                ->where('approvals.data.0.application_number', $approved->application_number)
            );

        $this->actingAs($this->admin)
            ->get(route('shm.approvals.index', ['status' => 'rejected']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('approvals.data', 1)
                ->where('approvals.data.0.application_number', $rejected->application_number)
            );

        $this->actingAs($this->admin)
            ->get(route('shm.approvals.index', ['status' => 'all']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('approvals.data', 3));
    }

    public function test_approval_list_search_by_application_number(): void
    {
        $target = $this->approvalStageApplication($this->marketing);
        $this->approvalStageApplication($this->marketing);

        $this->actingAs($this->admin)
            ->get(route('shm.approvals.index', ['search' => $target->application_number]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('approvals.data', 1)
                ->where('approvals.data.0.application_number', $target->application_number)
            );
    }

    public function test_approval_list_search_by_customer_name(): void
    {
        $customer = ShmCustomer::factory()->create(['name' => 'Budi Santoso Khusus']);
        $application = $this->createApplicationFor($this->marketing, ['shm_customer_id' => $customer->id]);
        $application->update(['status' => ShmApplicationStatus::Approval]);
        $this->approvalStageApplication($this->marketing);

        $this->actingAs($this->admin)
            ->get(route('shm.approvals.index', ['search' => 'Budi Santoso']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('approvals.data', 1)
                ->where('approvals.data.0.application_number', $application->application_number)
            );
    }

    public function test_approval_list_filter_by_memorandum_type(): void
    {
        $shmShgb = $this->approvalStageApplication($this->marketing, ShmMemorandum::TYPE_SHM_SHGB);
        $this->approvalStageApplication($this->marketing, ShmMemorandum::TYPE_BUYBACK);
        $this->approvalStageApplication($this->marketing); // tanpa kategori

        $this->actingAs($this->admin)
            ->get(route('shm.approvals.index', ['type' => ShmMemorandum::TYPE_SHM_SHGB]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('approvals.data', 1)
                ->where('approvals.data.0.application_number', $shmShgb->application_number)
                ->where('approvals.data.0.memorandum_type', ShmMemorandum::TYPE_SHM_SHGB)
            );

        $this->actingAs($this->admin)
            ->get(route('shm.approvals.index', ['type' => ShmMemorandum::TYPE_BUYBACK]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('approvals.data', 1));
    }

    public function test_approval_list_is_paginated(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->approvalStageApplication($this->marketing);
        }

        $this->actingAs($this->admin)
            ->get(route('shm.approvals.index', ['per_page' => 10]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('approvals.data', 10)
                ->where('approvals.last_page', 2)
                ->where('approvals.total', 12)
            );
    }

    public function test_approval_list_exposes_no_financial_fields(): void
    {
        $this->approvalStageApplication($this->marketing);

        $this->actingAs($this->admin)
            ->get(route('shm.approvals.index'))
            ->assertOk()
            ->assertInertia(function ($page) {
                $rows = json_encode($page->toArray()['props']['approvals']['data'] ?? []);

                foreach (['amount', 'nominal', 'interest', 'margin', 'plafond'] as $forbidden) {
                    $this->assertStringNotContainsString($forbidden, (string) $rows);
                }

                return $page;
            });
    }

    public function test_approval_list_is_scoped_to_owner_even_when_permission_is_granted(): void
    {
        // shm.create dibutuhkan agar fixture dapat membuat pengajuan melalui HTTP.
        $approver = $this->userWithPermissions(['shm.view', 'shm.create', 'shm.approval.decide']);

        $own = $this->createApplicationFor($approver);
        $own->update(['status' => ShmApplicationStatus::Approval]);

        $foreign = $this->approvalStageApplication($this->marketing);

        $this->actingAs($approver)
            ->get(route('shm.approvals.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('approvals.data', 1)
                ->where('approvals.data.0.application_number', $own->application_number)
            );

        // Cakupan berlaku juga pada halaman detail, bukan hanya daftar.
        $this->actingAs($approver)
            ->get(route('shm.approvals.show', $foreign->id))
            ->assertForbidden();
    }

    public function test_admin_sees_applications_from_all_marketing(): void
    {
        $first = $this->approvalStageApplication($this->marketing);
        $second = $this->approvalStageApplication($this->otherMarketing);

        $this->actingAs($this->admin)
            ->get(route('shm.approvals.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('approvals.data', 2));

        $this->assertSame(
            2,
            ShmApplication::query()->whereIn('id', [$first->id, $second->id])->count()
        );
    }

    /**
     * Fixture pengajuan yang keputusannya sudah tercatat pada tahap tertentu.
     */
    private function decidedApplication(ShmApplicationStatus $target): ShmApplication
    {
        $application = $this->approvalStageApplication($this->marketing);

        if ($target === ShmApplicationStatus::Approved) {
            $this->actingAs($this->admin)
                ->post(route('shm.applications.approve', $application->id), ['notes' => 'Disetujui fixture'])
                ->assertSessionHasNoErrors();
        } else {
            $this->actingAs($this->admin)
                ->post(route('shm.applications.reject', $application->id), ['notes' => 'Ditolak fixture'])
                ->assertSessionHasNoErrors();
        }

        return $application->refresh();
    }

    // -----------------------------------------------------------------
    // C. Detail approval: data existing, read-only kecuali keputusan
    // -----------------------------------------------------------------

    public function test_admin_can_open_approval_detail(): void
    {
        $application = $this->approvalStageApplication($this->marketing, ShmMemorandum::TYPE_SHM_SHGB);

        $this->actingAs($this->admin)
            ->get(route('shm.approvals.show', $application->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Shm/Approvals/Show')
                ->where('application.application_number', $application->application_number)
                ->where('application.status', ShmApplicationStatus::Approval->value)
                ->where('memorandum.type', ShmMemorandum::TYPE_SHM_SHGB)
                ->where('approval', null)
                ->where('can_decide', true)
                ->has('documents_summary.total')
                ->has('document_requirement_context.current')
                ->has('history')
            );
    }

    public function test_approval_detail_shows_recorded_decision(): void
    {
        $application = $this->approvalStageApplication($this->marketing);

        $this->actingAs($this->admin)
            ->post(route('shm.applications.approve', $application->id), ['notes' => 'Layak didanai'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->get(route('shm.approvals.show', $application->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('approval.status', ShmApproval::STATUS_APPROVED)
                ->where('approval.decider', $this->admin->name)
                ->where('approval.notes', 'Layak didanai')
                ->where('can_decide', false)
            );
    }

    public function test_approval_detail_document_context_follows_t53_taxonomy(): void
    {
        $application = $this->approvalStageApplication($this->marketing, ShmMemorandum::TYPE_BUYBACK);

        $this->actingAs($this->admin)
            ->get(route('shm.approvals.show', $application->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('document_requirement_context.current', ShmMemorandum::TYPE_BUYBACK)
            );
    }

    // -----------------------------------------------------------------
    // D. Keputusan: endpoint existing, ShmWorkflowService, audit existing
    // -----------------------------------------------------------------

    public function test_approve_uses_existing_workflow_and_sets_approved(): void
    {
        $application = $this->approvalStageApplication($this->marketing);

        $this->actingAs($this->admin)
            ->post(route('shm.applications.approve', $application->id), ['notes' => 'Setuju'])
            ->assertSessionHasNoErrors();

        $application->refresh();

        $this->assertSame(ShmApplicationStatus::Approved, $application->status);
        $this->assertNotNull($application->approved_at);

        $approval = ShmApproval::query()->where('shm_application_id', $application->id)->firstOrFail();
        $this->assertSame(ShmApproval::STATUS_APPROVED, $approval->status);
        $this->assertSame($this->admin->id, $approval->decided_by);
        $this->assertNotNull($approval->decided_at);
        $this->assertSame('Setuju', $approval->notes);

        $this->assertSame(1, AuditLog::query()
            ->where('action', 'shm.application.approved')
            ->count());
    }

    public function test_reject_uses_existing_workflow_and_sets_rejected(): void
    {
        $application = $this->approvalStageApplication($this->marketing);

        $this->actingAs($this->admin)
            ->post(route('shm.applications.reject', $application->id), ['notes' => 'Belum memenuhi syarat'])
            ->assertSessionHasNoErrors();

        $application->refresh();

        $this->assertSame(ShmApplicationStatus::Rejected, $application->status);
        $this->assertNotNull($application->rejected_at);

        $approval = ShmApproval::query()->where('shm_application_id', $application->id)->firstOrFail();
        $this->assertSame(ShmApproval::STATUS_REJECTED, $approval->status);
        $this->assertSame($this->admin->id, $approval->decided_by);
        $this->assertSame('Belum memenuhi syarat', $approval->notes);

        // Behavior existing: alasan penolakan juga tersimpan pada pengajuan.
        $this->assertSame('Belum memenuhi syarat', $application->notes);

        $this->assertSame(1, AuditLog::query()
            ->where('action', 'shm.application.rejected')
            ->count());
    }

    public function test_cannot_approve_application_outside_approval_stage(): void
    {
        $application = $this->createApplicationFor($this->marketing); // draft

        $this->actingAs($this->admin)
            ->post(route('shm.applications.approve', $application->id))
            ->assertSessionHasErrors('status');

        $application->refresh();
        $this->assertSame(ShmApplicationStatus::Draft, $application->status);
        $this->assertSame(0, ShmApproval::query()->count());
    }

    public function test_cannot_reject_application_outside_approval_stage(): void
    {
        $application = $this->createApplicationFor($this->marketing); // draft

        $this->actingAs($this->admin)
            ->post(route('shm.applications.reject', $application->id))
            ->assertSessionHasErrors('status');

        $application->refresh();
        $this->assertSame(ShmApplicationStatus::Draft, $application->status);
        $this->assertSame(0, ShmApproval::query()->count());
    }

    public function test_marketing_cannot_decide_even_through_direct_request(): void
    {
        $application = $this->approvalStageApplication($this->marketing);

        $this->actingAs($this->marketing)
            ->post(route('shm.applications.approve', $application->id))
            ->assertForbidden();

        $this->actingAs($this->marketing)
            ->post(route('shm.applications.reject', $application->id))
            ->assertForbidden();

        $application->refresh();
        $this->assertSame(ShmApplicationStatus::Approval, $application->status);
        $this->assertSame(0, ShmApproval::query()->count());
        $this->assertSame(0, AuditLog::query()
            ->whereIn('action', ['shm.application.approved', 'shm.application.rejected'])
            ->count());
    }

    public function test_decision_does_not_duplicate_approval_rows(): void
    {
        $application = $this->approvalStageApplication($this->marketing);

        $this->actingAs($this->admin)
            ->post(route('shm.applications.approve', $application->id))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post(route('shm.applications.approve', $application->id))
            ->assertSessionHasErrors('status');

        $this->assertSame(
            1,
            ShmApproval::query()->where('shm_application_id', $application->id)->count()
        );
    }

    public function test_memorandum_type_stays_consistent_after_decision(): void
    {
        $application = $this->approvalStageApplication($this->marketing, ShmMemorandum::TYPE_BUYBACK);

        $this->actingAs($this->admin)
            ->post(route('shm.applications.approve', $application->id))
            ->assertSessionHasNoErrors();

        $application->refresh();

        $this->assertSame(ShmMemorandum::TYPE_BUYBACK, $application->latestMemorandum->type);
    }

    public function test_rejected_application_can_return_to_draft_via_existing_endpoint(): void
    {
        $application = $this->approvalStageApplication($this->marketing);

        $this->actingAs($this->admin)
            ->post(route('shm.applications.reject', $application->id), ['notes' => 'Dokumen kurang'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post(route('shm.applications.return-to-draft', $application->id))
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->assertSame(ShmApplicationStatus::Draft, $application->status);
    }

    // -----------------------------------------------------------------
    // E. Regresi: workflow, T51/T52/T53 tidak berubah oleh T54
    // -----------------------------------------------------------------

    public function test_workflow_transition_map_is_unchanged(): void
    {
        // Approval tetap bercabang dua sesuai state machine Ticket 44.
        $this->assertSame(
            [ShmApplicationStatus::Approved, ShmApplicationStatus::Rejected],
            ShmApplicationStatus::Approval->allowedTransitions()
        );

        $this->assertTrue(ShmApplicationStatus::Rejected->canTransitionTo(ShmApplicationStatus::Draft));
        $this->assertFalse(ShmApplicationStatus::Approval->canTransitionTo(ShmApplicationStatus::DocumentCheck));
        $this->assertFalse(ShmApplicationStatus::Approved->canTransitionTo(ShmApplicationStatus::ReadyForDisbursement));
    }

    public function test_t53_document_requirement_taxonomy_is_unchanged(): void
    {
        $withType = $this->approvalStageApplication($this->marketing, ShmMemorandum::TYPE_SHM_SHGB);
        $context = ShmDocument::requirementContextFor($withType);

        $this->assertSame(ShmMemorandum::TYPE_SHM_SHGB, $context['current']);

        // Tanpa memorandum, konteks tetap pending — bukan diasumsi.
        $withoutType = $this->approvalStageApplication($this->marketing);
        $this->assertSame('pending', ShmDocument::requirementContextFor($withoutType)['current']);
    }

    public function test_t51_disbursement_gate_still_works_after_approval(): void
    {
        $application = $this->approvalStageApplication($this->marketing);

        $this->actingAs($this->admin)
            ->post(route('shm.applications.approve', $application->id))
            ->assertSessionHasNoErrors();

        // Tanpa dokumen lengkap, transisi menuju siap pencairan tetap ditolak.
        $this->actingAs($this->admin)
            ->post(route('shm.applications.mark-ready-for-disbursement', $application->id))
            ->assertSessionHasErrors('status');

        $application->refresh();
        $this->assertSame(ShmApplicationStatus::Approved, $application->status);

        // Peta transisi existing: approved -> document_check -> ready_for_disbursement.
        $this->actingAs($this->admin)
            ->post(route('shm.applications.start-document-check', $application->id))
            ->assertSessionHasNoErrors();

        // Tanpa dokumen lengkap, transisi menuju siap pencairan tetap ditolak.
        $this->actingAs($this->admin)
            ->post(route('shm.applications.mark-ready-for-disbursement', $application->id))
            ->assertSessionHasErrors('status');

        $application->refresh();
        $this->assertSame(ShmApplicationStatus::DocumentCheck, $application->status);

        ShmDocument::query()->create([
            'shm_application_id' => $application->id,
            'document_name' => 'Surat Pengantar',
            'status' => ShmDocument::STATUS_COMPLETE,
        ]);

        $this->actingAs($this->admin)
            ->post(route('shm.applications.mark-ready-for-disbursement', $application->id))
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->assertSame(ShmApplicationStatus::ReadyForDisbursement, $application->status);
    }

    public function test_t52_reopen_behavior_still_works_after_approval(): void
    {
        $application = $this->approvalStageApplication($this->marketing);

        $this->actingAs($this->admin)
            ->post(route('shm.applications.approve', $application->id))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post(route('shm.applications.start-document-check', $application->id))
            ->assertSessionHasNoErrors();

        ShmDocument::query()->create([
            'shm_application_id' => $application->id,
            'document_name' => 'Sertifikat',
            'status' => ShmDocument::STATUS_COMPLETE,
        ]);

        $this->actingAs($this->admin)
            ->post(route('shm.applications.mark-ready-for-disbursement', $application->id))
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->assertSame(ShmApplicationStatus::ReadyForDisbursement, $application->status);

        // Dokumen berubah pending secara manual: reopen ke document_check tetap
        // tersedia (T52), dan tidak ada status baru yang diperkenalkan.
        ShmDocument::query()
            ->where('shm_application_id', $application->id)
            ->update(['status' => ShmDocument::STATUS_PENDING]);

        $this->actingAs($this->admin)
            ->post(route('shm.applications.reopen-document-check', $application->id))
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->assertSame(ShmApplicationStatus::DocumentCheck, $application->status);
    }

    public function test_approval_module_introduces_no_new_statuses_or_permissions(): void
    {
        // Tidak ada status baru di registry workflow.
        $this->assertSame(
            ['draft', 'submitted', 'credit_checking', 'memorandum', 'approval', 'approved', 'document_check', 'rejected', 'ready_for_disbursement', 'disbursed', 'completed', 'cancelled'],
            ShmApplicationStatus::values()
        );

        // Permission yang dipakai tetap permission existing; tidak ada action
        // approval tambahan pada modul shm.
        $actions = array_keys(config('permissions.modules.shm.actions'));

        $this->assertContains('approval.decide', $actions);
        $this->assertNotContains('approval.view', $actions);
        $this->assertNotContains('approval.hierarchy', $actions);
    }

    // -----------------------------------------------------------------
    // F. Ticket 57 — pending approval visibility
    // -----------------------------------------------------------------

    /**
     * Memasukkan pengajuan ke tahap approval MELALUI endpoint workflow
     * existing (bukan update status langsung) sehingga baris ShmApproval
     * pending yang dibuat ShmWorkflowService::startApproval ikut tercipta.
     * Transisi mengikuti state machine existing: submit → credit checking →
     * memorandum → approval, dijalankan oleh pemilik pengajuan (owner).
     */
    private function pendingApprovalApplication(User $owner): ShmApplication
    {
        $application = $this->createApplicationFor($owner);

        $this->actingAs($owner)
            ->post(route('shm.applications.submit', $application->id))
            ->assertSessionHasNoErrors();
        $this->actingAs($owner)
            ->post(route('shm.applications.start-credit-checking', $application->id))
            ->assertSessionHasNoErrors();
        $this->actingAs($owner)
            ->post(route('shm.applications.start-memorandum', $application->id))
            ->assertSessionHasNoErrors();
        $this->actingAs($owner)
            ->post(route('shm.applications.start-approval', $application->id))
            ->assertSessionHasNoErrors();

        return $application->refresh();
    }

    public function test_entering_approval_stage_creates_pending_approval_row(): void
    {
        $application = $this->pendingApprovalApplication($this->marketing);

        $this->assertSame(ShmApplicationStatus::Approval, $application->status);
        $this->assertSame(
            1,
            ShmApproval::query()->where('shm_application_id', $application->id)->count()
        );
        $this->assertSame(
            ShmApproval::STATUS_PENDING,
            ShmApproval::query()->where('shm_application_id', $application->id)->value('status')
        );
    }

    public function test_pending_count_counts_only_pending_status(): void
    {
        $pending = $this->pendingApprovalApplication($this->marketing);
        $approved = $this->pendingApprovalApplication($this->marketing);
        $rejected = $this->pendingApprovalApplication($this->marketing);

        $this->actingAs($this->admin)
            ->post(route('shm.applications.approve', $approved->id), ['notes' => 'Ok'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->admin)
            ->post(route('shm.applications.reject', $rejected->id), ['notes' => 'Tidak'])
            ->assertSessionHasNoErrors();

        // Hanya status pending yang dihitung; approved dan rejected tidak.
        $this->assertSame(1, ShmApproval::pendingCountForUser(true, $this->admin->id));
        $this->assertSame($pending->id, $pending->refresh()->id);
    }

    public function test_pending_count_respects_view_all_scope(): void
    {
        $this->pendingApprovalApplication($this->marketing);
        $this->pendingApprovalApplication($this->otherMarketing);

        // view_all melihat seluruh pending.
        $this->assertSame(2, ShmApproval::pendingCountForUser(true, $this->admin->id));

        // Tanpa view_all hanya milik sendiri.
        $this->assertSame(1, ShmApproval::pendingCountForUser(false, $this->marketing->id));
        $this->assertSame(1, ShmApproval::pendingCountForUser(false, $this->otherMarketing->id));
    }

    public function test_approval_page_shows_pending_count_and_scope(): void
    {
        $this->pendingApprovalApplication($this->marketing);
        $this->pendingApprovalApplication($this->otherMarketing);

        $this->actingAs($this->admin)
            ->get(route('shm.approvals.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pending_count', 2)
            );

        // Pemilik restricted (dengan permission decide) hanya melihat miliknya.
        // shm.submit + shm.memorandum.update dibutuhkan agar fixture dapat
        // menempuh state machine existing hingga tahap approval.
        $approver = $this->userWithPermissions([
            'shm.view', 'shm.create', 'shm.submit', 'shm.memorandum.update', 'shm.approval.decide',
        ]);
        $own = $this->pendingApprovalApplication($approver);

        $this->actingAs($approver)
            ->get(route('shm.approvals.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('pending_count', 1));
    }

    public function test_shared_pending_count_is_hidden_from_users_without_approval_permission(): void
    {
        $this->pendingApprovalApplication($this->marketing);

        // Marketing/pengawas tanpa shm.approval.decide tidak menerima angka
        // pending sama sekali (prop shm = null) — tidak bisa dipakai memindai.
        $this->actingAs($this->marketing)
            ->get(route('shm.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('shm', null));

        $this->actingAs($this->pengawas)
            ->get(route('shm.dashboard'))
            ->assertForbidden();

        // Pemegang permission menerima angka yang benar.
        $this->actingAs($this->admin)
            ->get(route('shm.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('shm.pending_approvals.approvals', 1)
            );
    }

    public function test_opening_approval_pages_does_not_mutate_approvals_or_audit(): void
    {
        $application = $this->pendingApprovalApplication($this->marketing);
        $approval = ShmApproval::query()->where('shm_application_id', $application->id)->firstOrFail();
        $auditBefore = AuditLog::query()->count();

        $this->actingAs($this->admin)->get(route('shm.approvals.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('shm.approvals.show', $application->id))->assertOk();
        $this->actingAs($this->admin)->get(route('shm.dashboard'))->assertOk();

        $approval->refresh();
        $this->assertSame(ShmApproval::STATUS_PENDING, $approval->status);
        $this->assertNull($approval->decided_by);
        $this->assertNull($approval->decided_at);
        $this->assertSame(1, ShmApproval::query()->where('shm_application_id', $application->id)->count());
        $this->assertSame($auditBefore, AuditLog::query()->count());
    }

    public function test_deciding_pending_application_via_existing_workflow_still_works(): void
    {
        $application = $this->pendingApprovalApplication($this->marketing);

        $this->actingAs($this->admin)
            ->post(route('shm.applications.approve', $application->id), ['notes' => 'Setuju'])
            ->assertSessionHasNoErrors();

        // Baris pending yang sama di-update — tidak ada duplikasi.
        $this->assertSame(
            1,
            ShmApproval::query()->where('shm_application_id', $application->id)->count()
        );
        $this->assertSame(
            ShmApproval::STATUS_APPROVED,
            ShmApproval::query()->where('shm_application_id', $application->id)->value('status')
        );
        $this->assertSame(0, ShmApproval::pendingCountForUser(true, $this->admin->id));

        // Audit approval existing tetap tercatat.
        $this->assertSame(1, AuditLog::query()->where('action', 'shm.application.approved')->count());
    }

    public function test_reentering_approval_stage_does_not_duplicate_pending_row(): void
    {
        $application = $this->pendingApprovalApplication($this->marketing);

        $this->actingAs($this->admin)
            ->post(route('shm.applications.reject', $application->id), ['notes' => 'Kurang'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->admin)
            ->post(route('shm.applications.return-to-draft', $application->id))
            ->assertSessionHasNoErrors();

        // Putaran kedua mengikuti state machine existing dari draft.
        $this->actingAs($this->marketing)
            ->post(route('shm.applications.submit', $application->id))
            ->assertSessionHasNoErrors();
        $this->actingAs($this->marketing)
            ->post(route('shm.applications.start-credit-checking', $application->id))
            ->assertSessionHasNoErrors();
        $this->actingAs($this->marketing)
            ->post(route('shm.applications.start-memorandum', $application->id))
            ->assertSessionHasNoErrors();
        $this->actingAs($this->marketing)
            ->post(route('shm.applications.start-approval', $application->id))
            ->assertSessionHasNoErrors();

        // firstOrCreate idempoten: tetap satu baris.
        $this->assertSame(
            1,
            ShmApproval::query()->where('shm_application_id', $application->id)->count()
        );
        $this->assertSame(
            ShmApproval::STATUS_PENDING,
            ShmApproval::query()->where('shm_application_id', $application->id)->value('status')
        );
    }

    // -----------------------------------------------------------------
    // G. Ticket 58 — ringkasan antrean approval per marketing
    // -----------------------------------------------------------------

    public function test_marketing_queue_grouped_aggregate_is_performed_in_database(): void
    {
        $this->pendingApprovalApplication($this->marketing);
        $this->pendingApprovalApplication($this->marketing);
        $this->pendingApprovalApplication($this->otherMarketing);

        $result = ShmApproval::pendingCountByMarketing(true, $this->admin->id);

        $byId = array_column($result, null, 'marketing_id');

        $this->assertSame(2, $byId[$this->marketing->id]['pending_count']);
        $this->assertSame(1, $byId[$this->otherMarketing->id]['pending_count']);
        $this->assertSame($this->marketing->name, $byId[$this->marketing->id]['name']);
        $this->assertSame($this->otherMarketing->name, $byId[$this->otherMarketing->id]['name']);
    }

    public function test_marketing_queue_does_not_count_approved_or_rejected(): void
    {
        $pending = $this->pendingApprovalApplication($this->marketing);
        $approved = $this->pendingApprovalApplication($this->marketing);
        $rejected = $this->pendingApprovalApplication($this->marketing);

        $this->actingAs($this->admin)
            ->post(route('shm.applications.approve', $approved->id))
            ->assertSessionHasNoErrors();
        $this->actingAs($this->admin)
            ->post(route('shm.applications.reject', $rejected->id))
            ->assertSessionHasNoErrors();

        $result = ShmApproval::pendingCountByMarketing(true, $this->admin->id);

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['pending_count']);
        $this->assertSame($this->marketing->id, $result[0]['marketing_id']);
    }

    public function test_view_all_sees_all_marketing_with_pending(): void
    {
        $this->pendingApprovalApplication($this->marketing);
        $this->pendingApprovalApplication($this->otherMarketing);
        $this->pendingApprovalApplication($this->marketing);

        $this->actingAs($this->admin)
            ->get(route('shm.approvals.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('marketing_queue', 2)
                ->where('marketing_queue.0.pending_count', 2)
                ->where('marketing_queue.1.pending_count', 1)
            );
    }

    public function test_restricted_marketing_sees_only_themselves(): void
    {
        $approver = $this->userWithPermissions([
            'shm.view', 'shm.create', 'shm.submit', 'shm.memorandum.update', 'shm.approval.decide',
        ]);

        $this->pendingApprovalApplication($approver);
        $this->pendingApprovalApplication($this->marketing);
        $this->pendingApprovalApplication($this->otherMarketing);

        $this->actingAs($approver)
            ->get(route('shm.approvals.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('marketing_queue', 1)
                ->where('marketing_queue.0.marketing_id', $approver->id)
                ->where('marketing_queue.0.pending_count', 1)
            );
    }

    public function test_restricted_marketing_cannot_learn_other_marketing_pending_count(): void
    {
        $approver = $this->userWithPermissions([
            'shm.view', 'shm.create', 'shm.submit', 'shm.memorandum.update', 'shm.approval.decide',
        ]);

        $this->pendingApprovalApplication($this->marketing);
        $this->pendingApprovalApplication($this->marketing);

        $this->actingAs($approver)
            ->get(route('shm.approvals.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('marketing_queue', 0)
                ->where('marketing_queue', [])
            );

        $this->actingAs($approver)
            ->get(route('shm.approvals.index'))
            ->assertInertia(fn ($page) => $page->where('pending_count', 0));
    }

    public function test_marketing_without_permission_cannot_open_approval_with_queue(): void
    {
        $this->pendingApprovalApplication($this->marketing);

        $this->actingAs($this->marketing)
            ->get(route('shm.approvals.index'))
            ->assertForbidden();

        $this->actingAs($this->otherMarketing)
            ->get(route('shm.approvals.index'))
            ->assertForbidden();
    }

    public function test_marketing_with_pending_zero_shows_empty_state_not_other_marketing(): void
    {
        $approver = $this->userWithPermissions([
            'shm.view', 'shm.create', 'shm.submit', 'shm.memorandum.update', 'shm.approval.decide',
        ]);

        $this->pendingApprovalApplication($this->marketing);
        $this->pendingApprovalApplication($this->marketing);

        $this->actingAs($approver)
            ->get(route('shm.approvals.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('marketing_queue', 0)
            );
    }

    public function test_opening_approval_page_with_queue_does_not_mutate(): void
    {
        $application = $this->pendingApprovalApplication($this->marketing);
        $application2 = $this->pendingApprovalApplication($this->otherMarketing);
        $approvalBefore = ShmApproval::query()
            ->whereIn('shm_application_id', [$application->id, $application2->id])
            ->get();

        $auditBefore = AuditLog::query()->count();

        $this->actingAs($this->admin)
            ->get(route('shm.approvals.index'))
            ->assertOk();

        $this->actingAs($this->admin)
            ->get(route('shm.approvals.index'))
            ->assertInertia(fn ($page) => $page
                ->has('marketing_queue', 2)
            );

        $approvalsAfter = ShmApproval::query()
            ->whereIn('shm_application_id', [$application->id, $application2->id])
            ->get();

        $this->assertCount($approvalBefore->count(), $approvalsAfter);
        $approvalsAfter->each(function (ShmApproval $approval) use ($approvalBefore): void {
            $before = $approvalBefore->firstWhere('id', $approval->id);
            $this->assertSame($before?->status ?? '', $approval->status);
            $this->assertSame($before?->decided_by, $approval->decided_by);
        });
        $this->assertSame($auditBefore, AuditLog::query()->count());
    }

    public function test_marketing_queue_navigation_uses_existing_approval_route(): void
    {
        $this->pendingApprovalApplication($this->marketing);

        $this->actingAs($this->admin)
            ->get(route('shm.approvals.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('marketing_queue', 1));
    }
}
