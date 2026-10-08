<?php

namespace Tests\Feature;

use App\Enums\ShmApplicationStatus;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ShmApplication;
use App\Models\ShmApproval;
use App\Models\ShmCustomer;
use App\Models\ShmMemorandum;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ticket 56 - Dashboard pipeline & pemantauan operasional SHM.
 *
 * Menguji dashboard read-only yang seluruh angkanya dihitung dari status dan
 * kolom existing (semantics "Masuk" mengikuti Ticket 55: submitted_at terisi).
 * Dashboard tidak boleh mengubah status, membuat approval, atau menulis audit.
 *
 * Yang TIDAK diuji di sini karena bukan bagian ticket: KPI finansial, filter
 * tanggal, chart library, status baru.
 */
class ShmDashboardTest extends TestCase
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

    private function createApplicationFor(User $user): ShmApplication
    {
        $this->actingAs($user)
            ->post(route('shm.applications.store'), [
                'shm_customer_id' => $this->customer->id,
                'notes' => 'Pengajuan dashboard test',
            ])
            ->assertSessionHasNoErrors();

        return ShmApplication::query()->latest('id')->firstOrFail();
    }

    /**
     * Pengajuan fixture: draft bila $submitted false, masuk alur bila true.
     * Kolom timestamp workflow tidak fillable, sehingga dipakai forceFill.
     */
    private function application(User $owner, bool $submitted = true): ShmApplication
    {
        $application = $this->createApplicationFor($owner);

        if ($submitted) {
            $application->forceFill([
                'status' => ShmApplicationStatus::Submitted,
                'submitted_at' => now(),
            ])->save();
            $application->refresh();
        }

        return $application;
    }

    private function atStage(User $owner, ShmApplicationStatus $status, array $extra = []): ShmApplication
    {
        $application = $this->application($owner);

        $application->forceFill(array_merge([
            'status' => $status,
            'approved_at' => now(),
        ], $extra))->save();
        $application->refresh();

        return $application;
    }

    // -----------------------------------------------------------------
    // A. Akses & scope
    // -----------------------------------------------------------------

    public function test_authorized_users_can_open_shm_dashboard(): void
    {
        $this->actingAs($this->admin)
            ->get(route('shm.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Shm/Dashboard')
                ->has('metrics.applications.total')
                ->has('metrics.applications.masuk')
                ->has('metrics.memorandum_distribution')
                ->has('metrics.recent_applications')
            );

        $this->actingAs($this->superadmin)
            ->get(route('shm.dashboard'))
            ->assertOk();
    }

    public function test_unauthorized_users_cannot_open_shm_dashboard(): void
    {
        // Marketing memegang shm.view sehingga boleh; pengawas tidak.
        $this->actingAs($this->marketing)
            ->get(route('shm.dashboard'))
            ->assertOk();

        $this->actingAs($this->pengawas)
            ->get(route('shm.dashboard'))
            ->assertForbidden();
    }

    public function test_marketing_without_view_all_only_sees_own_applications(): void
    {
        $mine = $this->application($this->marketing);
        $this->application($this->otherMarketing);

        $this->actingAs($this->marketing)
            ->get(route('shm.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'own')
                ->where('metrics.applications.total', 1)
                ->where('metrics.recent_applications.0.id', $mine->id)
                ->has('metrics.recent_applications', 1)
            );
    }

    public function test_admin_with_view_all_sees_all_applications(): void
    {
        $this->application($this->marketing);
        $this->application($this->otherMarketing);

        $this->actingAs($this->admin)
            ->get(route('shm.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'all')
                ->where('metrics.applications.total', 2)
            );
    }

    // -----------------------------------------------------------------
    // B. Metrics per status existing
    // -----------------------------------------------------------------

    public function test_metrics_count_each_workflow_stage(): void
    {
        // Masuk (submitted_at terisi) — semantics Ticket 55.
        $this->application($this->marketing);

        $this->atStage($this->marketing, ShmApplicationStatus::CreditChecking);
        $this->atStage($this->marketing, ShmApplicationStatus::Memorandum);
        $this->atStage($this->marketing, ShmApplicationStatus::Approval);
        $this->atStage($this->marketing, ShmApplicationStatus::Approved);
        $this->atStage($this->marketing, ShmApplicationStatus::Rejected, ['approved_at' => null, 'rejected_at' => now()]);
        $this->atStage($this->marketing, ShmApplicationStatus::DocumentCheck);
        $this->atStage($this->marketing, ShmApplicationStatus::ReadyForDisbursement);
        $this->atStage($this->marketing, ShmApplicationStatus::Disbursed, ['disbursed_at' => now()]);
        $this->atStage($this->marketing, ShmApplicationStatus::Completed, [
            'disbursed_at' => now(),
            'completed_at' => now(),
        ]);

        // Draft tidak pernah masuk alur.
        $this->application($this->marketing, submitted: false);

        $this->actingAs($this->admin)
            ->get(route('shm.dashboard'))
            ->assertOk()
            ->assertInertia(function ($page) {
                $page->where('metrics.applications.total', 11)
                    ->where('metrics.applications.masuk', 10)
                    ->where('metrics.applications.by_status.1.total', 1)   // submitted
                    ->where('metrics.applications.by_status.2.total', 1)   // credit_checking
                    ->where('metrics.applications.by_status.3.total', 1)   // memorandum
                    ->where('metrics.applications.by_status.4.total', 1)   // approval
                    ->where('metrics.applications.by_status.5.total', 1)   // approved
                    ->where('metrics.applications.by_status.6.total', 1)   // document_check
                    ->where('metrics.applications.by_status.7.total', 1)   // rejected
                    ->where('metrics.applications.by_status.8.total', 1)   // ready_for_disbursement
                    ->where('metrics.applications.by_status.9.total', 1)   // disbursed
                    ->where('metrics.applications.by_status.10.total', 1); // completed
            });
    }

    // -----------------------------------------------------------------
    // C. Distribusi memorandum
    // -----------------------------------------------------------------

    public function test_memorandum_distribution_counts_by_latest_type(): void
    {
        $shgb = $this->application($this->marketing);
        $buyback = $this->application($this->marketing);

        ShmMemorandum::query()->create([
            'shm_application_id' => $shgb->id,
            'created_by' => $this->marketing->id,
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'status' => ShmMemorandum::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);

        ShmMemorandum::query()->create([
            'shm_application_id' => $buyback->id,
            'created_by' => $this->marketing->id,
            'type' => ShmMemorandum::TYPE_BUYBACK,
            'status' => ShmMemorandum::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('shm.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('metrics.memorandum_distribution.0.type', ShmMemorandum::TYPE_SHM_SHGB)
                ->where('metrics.memorandum_distribution.0.total', 1)
                ->where('metrics.memorandum_distribution.1.type', ShmMemorandum::TYPE_BUYBACK)
                ->where('metrics.memorandum_distribution.1.total', 1)
            );
    }

    public function test_application_without_memorandum_is_not_forced_into_a_category(): void
    {
        $this->application($this->marketing);

        $this->actingAs($this->admin)
            ->get(route('shm.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('metrics.memorandum_distribution.0.total', 0)
                ->where('metrics.memorandum_distribution.1.total', 0)
            );
    }

    // -----------------------------------------------------------------
    // D. Recent applications
    // -----------------------------------------------------------------

    public function test_recent_applications_are_limited_and_visible_only(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $this->application($this->marketing);
        }

        // Milik marketing lain tidak boleh bocor ke recent list marketing ini.
        $foreign = $this->application($this->otherMarketing);

        $this->actingAs($this->marketing)
            ->get(route('shm.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('metrics.recent_applications', 5)
                // Recent diurutkan updated_at desc; foreign app tidak ada di cakupan.
                ->where('metrics.recent_applications.0.id', fn ($id) => $id !== $foreign->id)
            );
    }

    // -----------------------------------------------------------------
    // E. Read-only jaminan
    // -----------------------------------------------------------------

    public function test_dashboard_does_not_change_status_or_create_records(): void
    {
        $application = $this->application($this->marketing);
        $statusBefore = $application->status;

        $this->actingAs($this->admin)->get(route('shm.dashboard'))->assertOk();

        $application->refresh();

        $this->assertSame($statusBefore, $application->status);
        $this->assertDatabaseCount('shm_approvals', 0);
        $this->assertSame(
            AuditLog::query()->where('auditable_type', ShmApplication::class)->count(),
            0,
            'Dashboard tidak boleh menulis audit log.'
        );
    }

    // -----------------------------------------------------------------
    // F. Ticket 57 — pending approval di dashboard
    // -----------------------------------------------------------------

    /** Fixture pengajuan tahap approval MELALUI endpoint workflow existing,
     * mengikuti state machine: submit → credit checking → memorandum → approval.
     */
    private function pendingApproval(User $owner): ShmApplication
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

    public function test_pending_approval_metric_counts_pending_status_only(): void
    {
        $approved = $this->pendingApproval($this->marketing);
        $rejected = $this->pendingApproval($this->marketing);
        $this->pendingApproval($this->marketing);

        $this->actingAs($this->admin)
            ->post(route('shm.applications.approve', $approved->id))
            ->assertSessionHasNoErrors();
        $this->actingAs($this->admin)
            ->post(route('shm.applications.reject', $rejected->id))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->get(route('shm.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('metrics.pending_approval', 1));
    }

    public function test_pending_approval_metric_follows_ownership_scope(): void
    {
        $this->pendingApproval($this->marketing);
        $this->pendingApproval($this->otherMarketing);

        $this->actingAs($this->marketing)
            ->get(route('shm.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('metrics.pending_approval', null)
            );

        $adminOwn = $this->pendingApproval($this->admin);

        // Admin punya view_all + approval.decide: seluruh 3 pending.
        $this->actingAs($this->admin)
            ->get(route('shm.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('metrics.pending_approval', 3));

        // Superadmin bypass: angka yang sama.
        $this->actingAs($this->superadmin)
            ->get(route('shm.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('metrics.pending_approval', 3));

        // Pemegang decide tanpa view_all hanya melihat miliknya sendiri.
        $approver = User::factory()->create(['email_verified_at' => now()]);
        $role = Role::query()->create([
            'name' => 'approver-dashboard-'.uniqid(),
            'display_name' => 'Approver Dashboard',
            'is_system' => false,
        ]);
        $role->permissions()->attach(
            Permission::query()->whereIn('name', [
                'shm.view', 'shm.create', 'shm.submit', 'shm.memorandum.update', 'shm.approval.decide',
            ])->pluck('id')
        );
        $approver->roles()->attach($role->id);
        $own = $this->pendingApproval($approver);

        $this->actingAs($approver)
            ->get(route('shm.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('metrics.pending_approval', 1));
    }

    public function test_pending_approval_not_exposed_to_users_without_decide_permission(): void
    {
        $this->pendingApproval($this->marketing);

        // Marketing hanya memegang shm.view: angka tidak dikirim (null).
        $this->actingAs($this->marketing)
            ->get(route('shm.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('metrics.pending_approval', null)
            );
    }

    public function test_opening_dashboard_does_not_mutate_pending_approvals(): void
    {
        $application = $this->pendingApproval($this->marketing);
        $approvalId = ShmApproval::query()
            ->where('shm_application_id', $application->id)
            ->value('id');
        $statusBefore = ShmApproval::query()->value('status', $approvalId);

        $this->actingAs($this->admin)->get(route('shm.dashboard'))->assertOk();

        $this->assertSame(
            $statusBefore,
            ShmApproval::query()->whereKey($approvalId)->value('status')
        );
        $this->assertSame(1, ShmApproval::query()->count());
    }

    // -----------------------------------------------------------------
    // G. Ticket 59 — Marketing Operational Summary
    // -----------------------------------------------------------------

    public function test_marketing_summary_shows_all_marketing_with_view_all(): void
    {
        $this->application($this->marketing);
        $this->application($this->otherMarketing);

        $this->actingAs($this->admin)
            ->get(route('shm.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('metrics.marketing_summary', 2)
            );
    }

    public function test_marketing_summary_restricted_user_sees_only_themselves(): void
    {
        $this->application($this->marketing);
        $this->application($this->otherMarketing);

        $this->actingAs($this->marketing)
            ->get(route('shm.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('metrics.marketing_summary', 1)
                ->where('metrics.marketing_summary.0.marketing_id', $this->marketing->id)
            );
    }

    public function test_marketing_summary_restricted_user_cannot_see_other_marketing(): void
    {
        $this->application($this->marketing);
        $this->application($this->otherMarketing);

        $this->actingAs($this->marketing)
            ->get(route('shm.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('metrics.marketing_summary', 1)
                ->where('metrics.marketing_summary.0.marketing_id', $this->marketing->id)
            );
    }

    public function test_marketing_summary_counts_total_correctly(): void
    {
        $this->application($this->marketing);
        $this->application($this->marketing);
        $this->application($this->marketing, submitted: false);

        $this->actingAs($this->marketing)
            ->get(route('shm.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('metrics.marketing_summary.0.total', 3)
                ->where('metrics.marketing_summary.0.masuk', 2)
            );
    }

    public function test_marketing_summary_counts_masuk_using_submitted_at_not_null(): void
    {
        $this->application($this->marketing); // submitted
        $this->application($this->marketing, submitted: false); // not submitted

        $this->actingAs($this->marketing)
            ->get(route('shm.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('metrics.marketing_summary.0.masuk', 1)
            );
    }

    public function test_marketing_summary_counts_all_statuses_correctly(): void
    {
        $this->atStage($this->marketing, ShmApplicationStatus::CreditChecking);
        $this->atStage($this->marketing, ShmApplicationStatus::Memorandum);
        $this->atStage($this->marketing, ShmApplicationStatus::Approval);
        $this->atStage($this->marketing, ShmApplicationStatus::Approved);
        $this->atStage($this->marketing, ShmApplicationStatus::Rejected, ['approved_at' => null, 'rejected_at' => now()]);
        $this->atStage($this->marketing, ShmApplicationStatus::DocumentCheck);
        $this->atStage($this->marketing, ShmApplicationStatus::ReadyForDisbursement);
        $this->atStage($this->marketing, ShmApplicationStatus::Disbursed, ['disbursed_at' => now()]);
        $this->atStage($this->marketing, ShmApplicationStatus::Completed, ['disbursed_at' => now(), 'completed_at' => now()]);

        $this->actingAs($this->marketing)
            ->get(route('shm.dashboard'))
            ->assertOk()
            ->assertInertia(function ($page) {
                $summary = $page->toArray()['props']['metrics']['marketing_summary'][0] ?? [];
                $this->assertSame(9, $summary['total'] ?? 0);
                $this->assertSame(9, $summary['masuk'] ?? 0);
                $this->assertSame(1, $summary['credit_checking'] ?? 0);
                $this->assertSame(1, $summary['memorandum'] ?? 0);
                $this->assertSame(1, $summary['approval'] ?? 0);
                $this->assertSame(1, $summary['approved'] ?? 0);
                $this->assertSame(1, $summary['rejected'] ?? 0);
                $this->assertSame(1, $summary['document_check'] ?? 0);
                $this->assertSame(1, $summary['ready_for_disbursement'] ?? 0);
                $this->assertSame(1, $summary['disbursed'] ?? 0);
                $this->assertSame(1, $summary['completed'] ?? 0);
            });
    }

    public function test_marketing_without_application_not_shown(): void
    {
        $this->actingAs($this->marketing)
            ->get(route('shm.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('metrics.marketing_summary', 0)
            );
    }

    public function test_marketing_summary_pending_approval_uses_pending_status(): void
    {
        $approved = $this->pendingApproval($this->marketing);
        $this->pendingApproval($this->marketing);

        $this->actingAs($this->admin)
            ->post(route('shm.applications.approve', $approved->id))
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->get(route('shm.dashboard'))
            ->assertOk()
            ->assertInertia(function ($page) {
                $summaries = collect($page->toArray()['props']['metrics']['marketing_summary'] ?? []);
                $summary = $summaries->firstWhere('marketing_id', $this->marketing->id);
                $this->assertSame(2, $summary['total'] ?? 0);
                $this->assertSame(1, $summary['pending_approval'] ?? 0);
            });
    }

    public function test_marketing_summary_pending_approval_follows_ownership(): void
    {
        $this->pendingApproval($this->marketing);
        $this->pendingApproval($this->otherMarketing);

        $this->actingAs($this->marketing)
            ->get(route('shm.dashboard'))
            ->assertOk()
            ->assertInertia(function ($page) {
                $summaries = collect($page->toArray()['props']['metrics']['marketing_summary'] ?? []);
                $this->assertCount(1, $summaries);
                $this->assertSame($this->marketing->id, $summaries[0]['marketing_id']);
                $this->assertSame(1, $summaries[0]['pending_approval'] ?? 0);
            });
    }

    public function test_marketing_summary_restricted_user_not_exposed_other_marketing_pending(): void
    {
        $this->pendingApproval($this->otherMarketing);
        $this->pendingApproval($this->otherMarketing);

        $this->actingAs($this->marketing)
            ->get(route('shm.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('metrics.marketing_summary', 0)
            );
    }

    /**
     * T61-FIX — query ambil marketing dengan pending approval melakukan JOIN
     * ke shm_applications, yang juga memiliki kolom `status`.
     *
     * Kondisi pending HARUS merujuk shm_approvals.status; status pengajuan
     * tidak boleh ikut mengendalikan hitungan pending approval, dan pembukaan
     * dashboard tidak boleh melempar SQLSTATE[42702] (ambiguous column).
     */
    public function test_pending_approval_join_filters_approval_status_not_application_status(): void
    {
        // Marketing A: baris approval tetap pending, sementara status
        // pengajuan dipaksa keluar dari tahap approval. Hitungan pending
        // tetap 1 karena hanya shm_approvals.status yang menentukan.
        $ownApplication = $this->pendingApproval($this->marketing);
        $ownApplication->forceFill(['status' => ShmApplicationStatus::Submitted])->save();

        // Marketing B: approval sudah diputuskan (approved), tetapi status
        // pengajuan dipaksa kembali ke tahap approval. TIDAK dihitung pending.
        $otherApplication = $this->pendingApproval($this->otherMarketing);
        $this->actingAs($this->admin)
            ->post(route('shm.applications.approve', $otherApplication->id))
            ->assertSessionHasNoErrors();
        $otherApplication->forceFill(['status' => ShmApplicationStatus::Approval])->save();

        // Pembukaan dashboard menjalankan query JOIN distinct per marketing:
        // harus 200 tanpa SQLSTATE[42702], dengan hasil bisnis yang benar.
        $this->actingAs($this->admin)
            ->get(route('shm.dashboard'))
            ->assertOk()
            ->assertInertia(function ($page) {
                $summaries = collect($page->toArray()['props']['metrics']['marketing_summary'] ?? []);

                $own = $summaries->firstWhere('marketing_id', $this->marketing->id);
                $other = $summaries->firstWhere('marketing_id', $this->otherMarketing->id);

                // Pending approval hanya ditentukan shm_approvals.status = pending.
                $this->assertSame(1, $own['pending_approval'] ?? 0);
                $this->assertNotNull($other);
                $this->assertSame(0, $other['pending_approval'] ?? 0);
                // Grouping per marketing tetap benar: baris lain tetap ada
                // karena jumlah pengajunya > 0.
                $this->assertSame(1, $other['total'] ?? 0);
                $this->assertSame(1, $own['total'] ?? 0);
            });

        // Restricted user: query JOIN yang sama tetap dibatasi ownership.
        $this->actingAs($this->marketing)
            ->get(route('shm.dashboard'))
            ->assertOk()
            ->assertInertia(function ($page) {
                $summaries = collect($page->toArray()['props']['metrics']['marketing_summary'] ?? []);

                $this->assertCount(1, $summaries);
                $this->assertSame($this->marketing->id, $summaries[0]['marketing_id']);
                $this->assertSame(1, $summaries[0]['pending_approval'] ?? 0);
            });
    }

    public function test_marketing_summary_does_not_mutate_data(): void
    {
        $app = $this->application($this->marketing);
        $pending = $this->pendingApproval($this->marketing);
        $appCount = ShmApplication::query()->count();
        $appStatus = $app->status;
        $pendingCount = ShmApproval::query()->count();
        $pendingStatus = $pending->approvals()->first()->status;

        $this->actingAs($this->admin)->get(route('shm.dashboard'))->assertOk();

        $this->assertSame($appCount, ShmApplication::query()->count());
        $this->assertSame($appStatus, $app->fresh()->status);
        $this->assertSame($pendingCount, ShmApproval::query()->count());
        $this->assertSame($pendingStatus, $pending->approvals()->first()->fresh()->status);
    }
}
