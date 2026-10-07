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
 * Ticket 52 — Reopen policy: revalidasi kelengkapan dokumen.
 *
 * Reopen hanya dari ready_for_disbursement; hanya jika dokumen tidak lengkap;
 * tidak ada status baru; disbursed/completed/state lain tidak boleh reopen.
 * Tidak ada automatic reopen saat dokumen diubah.
 */
class ShmReopenDocumentCheckTest extends TestCase
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

    private function applicationAt(ShmApplicationStatus $status): ShmApplication
    {
        return $this->createApplicationFor($this->marketing, status: $status);
    }

    private function createApplicationFor(User $owner, ShmApplicationStatus $status = ShmApplicationStatus::Draft): ShmApplication
    {
        $this->actingAs($owner)
            ->post(route('shm.applications.store'), [
                'shm_customer_id' => $this->customer->id,
                'notes' => 'Fixture reopen',
            ])
            ->assertSessionHasNoErrors();

        $application = ShmApplication::query()->latest('id')->firstOrFail();
        $application->update(['status' => $status->value]);

        return $application->refresh();
    }

    private function addDocument(ShmApplication $application, string $status, string $name = 'Dokumen'): ShmDocument
    {
        return ShmDocument::query()->create([
            'shm_application_id' => $application->id,
            'document_name' => $name,
            'status' => $status,
        ]);
    }

    // -----------------------------------------------------------------
    // A. Reopen berjalan hanya dari ready_for_disbursement + incomplete
    // -----------------------------------------------------------------

    public function test_reopen_from_ready_for_disbursement_when_incomplete(): void
    {
        $application = $this->applicationAt(ShmApplicationStatus::ReadyForDisbursement);
        $this->addDocument($application, ShmDocument::STATUS_COMPLETE, 'Dokumen A');
        $this->addDocument($application, ShmDocument::STATUS_PENDING, 'Dokumen B');

        $this->actingAs($this->admin)
            ->post(route('shm.applications.reopen-document-check', $application->id))
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->assertSame(ShmApplicationStatus::DocumentCheck, $application->status);
        $this->assertNotNull($application->fresh()->updated_at);
    }

    public function test_reopen_second_document_pending(): void
    {
        $application = $this->applicationAt(ShmApplicationStatus::ReadyForDisbursement);
        $this->addDocument($application, ShmDocument::STATUS_COMPLETE, 'Dokumen A');
        $this->addDocument($application, ShmDocument::STATUS_COMPLETE, 'Dokumen B');
        $this->addDocument($application, ShmDocument::STATUS_PENDING, 'Dokumen C');

        $this->actingAs($this->admin)
            ->post(route('shm.applications.reopen-document-check', $application->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(ShmApplicationStatus::DocumentCheck, $application->refresh()->status);
    }

    public function test_reopen_with_no_documents_is_allowed(): void
    {
        $application = $this->applicationAt(ShmApplicationStatus::ReadyForDisbursement);

        $this->assertFalse($application->documentsComplete());

        $this->actingAs($this->admin)
            ->post(route('shm.applications.reopen-document-check', $application->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(ShmApplicationStatus::DocumentCheck, $application->refresh()->status);
    }

    // -----------------------------------------------------------------
    // B. Reopen ditolak bila dokumen masih lengkap
    // -----------------------------------------------------------------

    public function test_reopen_rejected_when_documents_still_complete(): void
    {
        $application = $this->applicationAt(ShmApplicationStatus::ReadyForDisbursement);
        $this->addDocument($application, ShmDocument::STATUS_COMPLETE, 'Dokumen A');
        $this->addDocument($application, ShmDocument::STATUS_NOT_APPLICABLE, 'Dokumen B');

        $this->actingAs($this->admin)
            ->post(route('shm.applications.reopen-document-check', $application->id))
            ->assertSessionHasErrors('status');

        $this->assertSame(ShmApplicationStatus::ReadyForDisbursement, $application->refresh()->status);
    }

    // -----------------------------------------------------------------
    // C. Reopen ditolak dari status lain
    // -----------------------------------------------------------------

    public function test_reopen_rejected_from_approved(): void
    {
        $application = $this->applicationAt(ShmApplicationStatus::Approved);
        $this->addDocument($application, ShmDocument::STATUS_PENDING, 'Dokumen A');

        $this->actingAs($this->admin)
            ->post(route('shm.applications.reopen-document-check', $application->id))
            ->assertSessionHasErrors('status');

        $this->assertSame(ShmApplicationStatus::Approved, $application->refresh()->status);
    }

    public function test_reopen_rejected_from_document_check(): void
    {
        $application = $this->applicationAt(ShmApplicationStatus::DocumentCheck);
        $this->addDocument($application, ShmDocument::STATUS_PENDING, 'Dokumen A');

        $this->actingAs($this->admin)
            ->post(route('shm.applications.reopen-document-check', $application->id))
            ->assertSessionHasErrors('status');

        $this->assertSame(ShmApplicationStatus::DocumentCheck, $application->refresh()->status);
    }

    public function test_reopen_rejected_from_disbursed(): void
    {
        $application = $this->applicationAt(ShmApplicationStatus::Disbursed);
        $this->addDocument($application, ShmDocument::STATUS_PENDING, 'Dokumen A');

        $this->actingAs($this->admin)
            ->post(route('shm.applications.reopen-document-check', $application->id))
            ->assertSessionHasErrors('status');

        $this->assertSame(ShmApplicationStatus::Disbursed, $application->refresh()->status);
    }

    public function test_reopen_rejected_from_completed(): void
    {
        $application = $this->applicationAt(ShmApplicationStatus::Completed);
        $this->addDocument($application, ShmDocument::STATUS_COMPLETE, 'Dokumen A');

        $this->actingAs($this->admin)
            ->post(route('shm.applications.reopen-document-check', $application->id))
            ->assertSessionHasErrors('status');

        $this->assertSame(ShmApplicationStatus::Completed, $application->refresh()->status);
    }

    // -----------------------------------------------------------------
    // D. Tidak ada automatic reopen saat dokumen diubah
    // -----------------------------------------------------------------

    public function test_marking_document_pending_does_not_auto_reopen(): void
    {
        $application = $this->applicationAt(ShmApplicationStatus::ReadyForDisbursement);
        $doc = $this->addDocument($application, ShmDocument::STATUS_COMPLETE, 'Surat Pengantar');

        $this->actingAs($this->admin)
            ->put(route('shm.applications.document', $application->id), [
                'document_name' => 'Surat Pengantar',
                'status' => ShmDocument::STATUS_PENDING,
                'notes' => 'Revisi checker',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(ShmApplicationStatus::ReadyForDisbursement, $application->refresh()->status);
        $this->assertSame(ShmDocument::STATUS_PENDING, $doc->refresh()->status);
    }

    // -----------------------------------------------------------------
    // E. Setelah reopen, dokumen lengkap lagi ikut gate T51
    // -----------------------------------------------------------------

    public function test_after_reopen_ready_for_disbursement_still_requires_completeness(): void
    {
        $application = $this->applicationAt(ShmApplicationStatus::ReadyForDisbursement);
        $this->addDocument($application, ShmDocument::STATUS_PENDING, 'Dokumen A');

        $this->actingAs($this->admin)
            ->post(route('shm.applications.reopen-document-check', $application->id))
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->assertSame(ShmApplicationStatus::DocumentCheck, $application->status);

        $pendingDoc = ShmDocument::query()
            ->where('shm_application_id', $application->id)
            ->where('status', ShmDocument::STATUS_PENDING)
            ->first();

        $this->assertNotNull($pendingDoc);
        $pendingDoc->status = ShmDocument::STATUS_COMPLETE;
        $pendingDoc->save();

        $application->refresh();
        $this->assertTrue($application->documentsComplete());

        $this->actingAs($this->admin)
            ->post(route('shm.applications.mark-ready-for-disbursement', $application->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(ShmApplicationStatus::ReadyForDisbursement, $application->refresh()->status);
    }

    // -----------------------------------------------------------------
    // F. Audit tepat satu & timeline
    // -----------------------------------------------------------------

    public function test_reopen_writes_exactly_one_status_changed_audit(): void
    {
        $application = $this->applicationAt(ShmApplicationStatus::ReadyForDisbursement);
        $this->addDocument($application, ShmDocument::STATUS_PENDING, 'Dokumen A');

        $before = AuditLog::query()->where('object_id', $application->id)->count();

        $this->actingAs($this->admin)
            ->post(route('shm.applications.reopen-document-check', $application->id))
            ->assertSessionHasNoErrors();

        $application->refresh();

        $this->assertSame($before + 1, AuditLog::query()->where('object_id', $application->id)->count());
        $this->assertSame(
            1,
            AuditLog::query()
                ->where('object_id', $application->id)
                ->where('action', 'shm.application.status_changed')
                ->where('changes->to', ShmApplicationStatus::DocumentCheck->value)
                ->count()
        );
    }

    // -----------------------------------------------------------------
    // G. Konkurensi: reopen membaca state dokumen terkini
    // -----------------------------------------------------------------

    public function test_reopen_rejected_when_another_user_completed_document(): void
    {
        $application = $this->applicationAt(ShmApplicationStatus::ReadyForDisbursement);
        $this->addDocument($application, ShmDocument::STATUS_PENDING, 'Dokumen A');

        ShmDocument::where('shm_application_id', $application->id)
            ->where('document_name', 'Dokumen A')
            ->update(['status' => ShmDocument::STATUS_COMPLETE]);

        $this->actingAs($this->admin)
            ->post(route('shm.applications.reopen-document-check', $application->id))
            ->assertSessionHasErrors('status');

        $this->assertSame(ShmApplicationStatus::ReadyForDisbursement, $application->refresh()->status);
    }

    // -----------------------------------------------------------------
    // H. Authorization & ownership
    // -----------------------------------------------------------------

    public function test_reopen_rejected_for_unauthorized_user(): void
    {
        $application = $this->applicationAt(ShmApplicationStatus::ReadyForDisbursement);
        $this->addDocument($application, ShmDocument::STATUS_PENDING, 'Dokumen A');

        $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
            ->post(route('shm.applications.reopen-document-check', $application->id))
            ->assertForbidden();

        $this->assertSame(ShmApplicationStatus::ReadyForDisbursement, $application->refresh()->status);
    }

    public function test_marketing_can_only_reopen_own_application(): void
    {
        $owner = $this->userWithRole('marketing');
        $other = $this->userWithRole('marketing');
        $application = $this->createApplicationFor($owner, ShmApplicationStatus::ReadyForDisbursement);
        $this->addDocument($application, ShmDocument::STATUS_PENDING, 'Dokumen A');

        $this->actingAs($other)
            ->post(route('shm.applications.reopen-document-check', $application->id))
            ->assertForbidden();

        $this->assertSame(ShmApplicationStatus::ReadyForDisbursement, $application->refresh()->status);
    }

    public function test_admin_can_reopen_ownerless_context(): void
    {
        $application = $this->applicationAt(ShmApplicationStatus::ReadyForDisbursement);
        $this->addDocument($application, ShmDocument::STATUS_PENDING, 'Dokumen A');

        $this->actingAs($this->admin)
            ->post(route('shm.applications.reopen-document-check', $application->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(ShmApplicationStatus::DocumentCheck, $application->refresh()->status);
    }

    // -----------------------------------------------------------------
    // I. Direct POST (tanpa frontend) tetap terkena gate
    // -----------------------------------------------------------------

    public function test_direct_post_without_frontend_is_accepted_when_allowed(): void
    {
        $application = $this->applicationAt(ShmApplicationStatus::ReadyForDisbursement);
        $this->addDocument($application, ShmDocument::STATUS_PENDING, 'Dokumen A');

        $this->actingAs($this->admin)
            ->post(route('shm.applications.reopen-document-check', $application->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(ShmApplicationStatus::DocumentCheck, $application->refresh()->status);
    }

    public function test_direct_post_with_complete_document_is_rejected(): void
    {
        $application = $this->applicationAt(ShmApplicationStatus::ReadyForDisbursement);
        $this->addDocument($application, ShmDocument::STATUS_COMPLETE, 'Dokumen A');

        $this->actingAs($this->admin)
            ->post(route('shm.applications.reopen-document-check', $application->id))
            ->assertSessionHasErrors('status');

        $this->assertSame(ShmApplicationStatus::ReadyForDisbursement, $application->refresh()->status);
    }

    public function test_direct_post_from_disbursed_is_rejected(): void
    {
        $application = $this->applicationAt(ShmApplicationStatus::Disbursed);
        $this->addDocument($application, ShmDocument::STATUS_PENDING, 'Dokumen A');

        $this->actingAs($this->admin)
            ->post(route('shm.applications.reopen-document-check', $application->id))
            ->assertSessionHasErrors('status');

        $this->assertSame(ShmApplicationStatus::Disbursed, $application->refresh()->status);
    }

    public function test_direct_post_from_completed_is_rejected(): void
    {
        $application = $this->applicationAt(ShmApplicationStatus::Completed);
        $this->addDocument($application, ShmDocument::STATUS_PENDING, 'Dokumen A');

        $this->actingAs($this->admin)
            ->post(route('shm.applications.reopen-document-check', $application->id))
            ->assertSessionHasErrors('status');

        $this->assertSame(ShmApplicationStatus::Completed, $application->refresh()->status);
    }
}
