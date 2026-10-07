<?php

namespace Tests\Feature;

use App\Enums\ShmApplicationStatus;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\ShmApplication;
use App\Models\ShmCustomer;
use App\Models\ShmDocument;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ticket 51 — Gate kelengkapan dokumen pada pencairan.
 *
 * Membuktikan bahwa invariant "pengajuan tidak boleh masuk tahap siap
 * pencairan/dicairkan selama documentsComplete() false" ditegakkan di
 * ShmWorkflowService (single source of truth), bukan di controller/frontend.
 *
 * Semantik kelengkapan mengikuti model existing:
 * - complete / not_applicable  => lengkap
 * - pending                    => belum lengkap
 * - tanpa item dokumen         => belum lengkap (behavior existing)
 */
class ShmDisbursementGateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $marketing;

    private ShmCustomer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->admin = $this->userWithRole('admin');
        $this->marketing = $this->userWithRole('marketing');
        $this->customer = ShmCustomer::factory()->create();
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', $roleName)->value('id'));

        return $user;
    }

    /**
     * Buat pengajuan milik Marketing lewat HTTP, lalu pindahkan ke tahap uji.
     */
    private function applicationAt(ShmApplicationStatus $status): ShmApplication
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.applications.store'), [
                'shm_customer_id' => $this->customer->id,
                'notes' => 'Fixture gate pencairan',
            ])
            ->assertSessionHasNoErrors();

        $application = ShmApplication::query()->latest('id')->firstOrFail();
        $application->update(['status' => $status]);

        return $application->refresh();
    }

    private function addDocument(
        ShmApplication $application,
        string $status,
        string $name = 'Dokumen',
    ): ShmDocument {
        return ShmDocument::query()->create([
            'shm_application_id' => $application->id,
            'document_name' => $name,
            'status' => $status,
        ]);
    }

    // -----------------------------------------------------------------
    // A. Gate pada transisi -> ready_for_disbursement
    // -----------------------------------------------------------------

    public function test_ready_for_disbursement_blocked_when_document_pending(): void
    {
        $application = $this->applicationAt(ShmApplicationStatus::DocumentCheck);
        $this->addDocument($application, ShmDocument::STATUS_COMPLETE, 'Dokumen A');
        $this->addDocument($application, ShmDocument::STATUS_PENDING, 'Dokumen B');

        $this->actingAs($this->admin)
            ->post(route('shm.applications.mark-ready-for-disbursement', $application->id))
            ->assertSessionHasErrors('status');

        $this->assertSame(
            ShmApplicationStatus::DocumentCheck,
            $application->refresh()->status,
        );
    }

    public function test_ready_for_disbursement_blocked_with_multiple_pending(): void
    {
        $application = $this->applicationAt(ShmApplicationStatus::DocumentCheck);
        $this->addDocument($application, ShmDocument::STATUS_COMPLETE, 'Dokumen A');
        $this->addDocument($application, ShmDocument::STATUS_PENDING, 'Dokumen B');
        $this->addDocument($application, ShmDocument::STATUS_PENDING, 'Dokumen C');

        $this->actingAs($this->admin)
            ->post(route('shm.applications.mark-ready-for-disbursement', $application->id))
            ->assertSessionHasErrors('status');

        $this->assertFalse($application->refresh()->documentsComplete());
    }

    public function test_ready_for_disbursement_allowed_when_all_complete(): void
    {
        $application = $this->applicationAt(ShmApplicationStatus::DocumentCheck);
        $this->addDocument($application, ShmDocument::STATUS_COMPLETE, 'Dokumen A');
        $this->addDocument($application, ShmDocument::STATUS_COMPLETE, 'Dokumen B');

        $this->actingAs($this->admin)
            ->post(route('shm.applications.mark-ready-for-disbursement', $application->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ShmApplicationStatus::ReadyForDisbursement,
            $application->refresh()->status,
        );
    }

    public function test_not_applicable_counts_as_complete(): void
    {
        $application = $this->applicationAt(ShmApplicationStatus::DocumentCheck);
        $this->addDocument($application, ShmDocument::STATUS_COMPLETE, 'Dokumen A');
        $this->addDocument($application, ShmDocument::STATUS_NOT_APPLICABLE, 'Dokumen B');

        $this->assertTrue($application->refresh()->documentsComplete());

        $this->actingAs($this->admin)
            ->post(route('shm.applications.mark-ready-for-disbursement', $application->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ShmApplicationStatus::ReadyForDisbursement,
            $application->refresh()->status,
        );
    }

    public function test_ready_for_disbursement_blocked_when_no_documents(): void
    {
        $application = $this->applicationAt(ShmApplicationStatus::DocumentCheck);

        // Behavior existing: dokumen kosong => documentsComplete() false.
        $this->assertFalse($application->documentsComplete());

        $this->actingAs($this->admin)
            ->post(route('shm.applications.mark-ready-for-disbursement', $application->id))
            ->assertSessionHasErrors('status');

        $this->assertSame(
            ShmApplicationStatus::DocumentCheck,
            $application->refresh()->status,
        );
    }

    // -----------------------------------------------------------------
    // B. Gate pada transisi -> disbursed
    // -----------------------------------------------------------------

    public function test_disbursement_blocked_when_document_pending(): void
    {
        $application = $this->applicationAt(ShmApplicationStatus::ReadyForDisbursement);
        $this->addDocument($application, ShmDocument::STATUS_COMPLETE, 'Dokumen A');
        $this->addDocument($application, ShmDocument::STATUS_PENDING, 'Dokumen B');

        $this->actingAs($this->admin)
            ->post(route('shm.applications.disburse', $application->id))
            ->assertSessionHasErrors('status');

        $application->refresh();
        $this->assertSame(ShmApplicationStatus::ReadyForDisbursement, $application->status);
        $this->assertNull($application->disbursed_at);
    }

    public function test_disbursement_allowed_when_documents_complete(): void
    {
        $application = $this->applicationAt(ShmApplicationStatus::ReadyForDisbursement);
        $this->addDocument($application, ShmDocument::STATUS_COMPLETE, 'Dokumen A');
        $this->addDocument($application, ShmDocument::STATUS_NOT_APPLICABLE, 'Dokumen B');

        $this->actingAs($this->admin)
            ->post(route('shm.applications.disburse', $application->id))
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->assertSame(ShmApplicationStatus::Disbursed, $application->status);
        $this->assertNotNull($application->disbursed_at);
    }

    // -----------------------------------------------------------------
    // C. Keamanan state & audit
    // -----------------------------------------------------------------

    public function test_blocked_transition_writes_no_audit_event(): void
    {
        $application = $this->applicationAt(ShmApplicationStatus::DocumentCheck);
        $this->addDocument($application, ShmDocument::STATUS_PENDING, 'Dokumen A');

        $before = AuditLog::query()->count();

        $this->actingAs($this->admin)
            ->post(route('shm.applications.mark-ready-for-disbursement', $application->id))
            ->assertSessionHasErrors('status');

        $this->assertSame($before, AuditLog::query()->count());
        $this->assertSame(0, AuditLog::query()
            ->where('action', 'shm.application.status_changed')
            ->where('object_id', $application->id)
            ->count());
    }

    public function test_successful_transition_is_audited_exactly_once(): void
    {
        $application = $this->applicationAt(ShmApplicationStatus::DocumentCheck);
        $this->addDocument($application, ShmDocument::STATUS_COMPLETE, 'Dokumen A');

        $this->actingAs($this->admin)
            ->post(route('shm.applications.mark-ready-for-disbursement', $application->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, AuditLog::query()
            ->where('action', 'shm.application.status_changed')
            ->where('object_id', $application->id)
            ->count());
    }

    /**
     * CRITICAL: request langsung ke endpoint tanpa frontend tetap ditolak.
     *
     * Button yang disabled/hidden di UI BUKAN mekanisme keamanan. Test ini
     * mengirim POST mentah ke endpoint workflow dengan dokumen pending dan
     * memastikan backend menolaknya serta tidak mengubah state.
     */
    public function test_direct_endpoint_request_is_rejected_without_frontend(): void
    {
        $application = $this->applicationAt(ShmApplicationStatus::DocumentCheck);
        $this->addDocument($application, ShmDocument::STATUS_PENDING, 'Dokumen A');

        $response = $this->actingAs($this->admin)
            ->post(route('shm.applications.mark-ready-for-disbursement', $application->id));

        $response->assertSessionHasErrors('status');

        $application->refresh();
        $this->assertSame(ShmApplicationStatus::DocumentCheck, $application->status);
        $this->assertNull($application->disbursed_at);
    }

    // -----------------------------------------------------------------
    // D. Approval tidak di-bypass
    // -----------------------------------------------------------------

    public function test_approved_stage_cannot_skip_document_check_even_when_complete(): void
    {
        $application = $this->applicationAt(ShmApplicationStatus::Approved);
        $this->addDocument($application, ShmDocument::STATUS_COMPLETE, 'Dokumen A');

        // Approved hanya boleh -> DocumentCheck; dokumen lengkap tidak membuka pintu.
        $this->actingAs($this->admin)
            ->post(route('shm.applications.mark-ready-for-disbursement', $application->id))
            ->assertSessionHasErrors('status');

        $this->assertSame(ShmApplicationStatus::Approved, $application->refresh()->status);
    }
}
