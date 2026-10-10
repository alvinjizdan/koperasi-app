<?php

namespace Tests\Feature;

use App\Enums\ShmApplicationStatus;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\ShmApplication;
use App\Models\ShmCustomer;
use App\Models\ShmDocument;
use App\Models\ShmMemorandum;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ticket 53 — SHM document requirement taxonomy foundation.
 *
 * Fokus:
 * - Reuse existing source of truth (memorandum type) untuk membedakan
 *   SHM/SHGB vs Buyback sebagai satu-satunya konteks requirement hari ini.
 * - Tidak ada daftar dokumen wajib/opsional/kondisional yang diciptakan.
 * - Completeness semantics, T51 gate, T52 reopen, workflow status, dan
 *   audit tetap tidak berubah.
 */
class ShmDocumentTaxonomyTest extends TestCase
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

    private function createApplicationFor(User $owner, ShmApplicationStatus $status = ShmApplicationStatus::Draft): ShmApplication
    {
        $this->actingAs($owner)
            ->post(route('shm.applications.store'), [
                'shm_customer_id' => $this->customer->id,
                'notes' => 'Fixture taxonomy',
            ])
            ->assertSessionHasNoErrors();

        $application = ShmApplication::query()->latest('id')->firstOrFail();
        $application->update(['status' => $status->value]);

        return $application->refresh();
    }

    /**
     * Majukan pengajuan ke tahap DocumentCheck (setelah approval) supaya
     * dokumen dapat dicatat. Polinomial singkat untuk fixture jealousy.
     */
    private function advanceToDocumentCheck(User $actor, ShmApplication $application): ShmApplication
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.applications.submit', $application->id))
            ->assertSessionHasNoErrors();
        $application->refresh();

        $this->actingAs($this->marketing)
            ->post(route('shm.applications.credit-checking.start', $application->id))
            ->assertSessionHasNoErrors();
        $application->refresh();

        $this->actingAs($this->marketing)
            ->post(route('shm.applications.start-memorandum', $application->id))
            ->assertSessionHasNoErrors();
        $application->refresh();

        $this->actingAs($this->marketing)
            ->post(route('shm.applications.start-approval', $application->id))
            ->assertSessionHasNoErrors();
        $application->refresh();

        $this->actingAs($this->admin)
            ->post(route('shm.applications.approve', $application->id), ['notes' => 'Layak'])
            ->assertSessionHasNoErrors();
        $application->refresh();

        $this->actingAs($this->admin)
            ->post(route('shm.applications.start-document-check', $application->id))
            ->assertSessionHasNoErrors();
        $application->refresh();

        return $application;
    }

    // -----------------------------------------------------------------
    // A. Konteks requirement
    // -----------------------------------------------------------------

    public function test_requirement_context_pending_when_no_memorandum(): void
    {
        $application = $this->createApplicationFor($this->marketing);

        $context = ShmDocument::requirementContextFor($application);

        $this->assertSame('pending', $context['current']);
        $this->assertStringContainsString('belum dikonfigurasi', $context['note']);
    }

    public function test_requirement_context_reuses_shm_shgb_memorandum_type(): void
    {
        $application = $this->createApplicationFor($this->marketing);
        $application->refresh();

        ShmMemorandum::query()->updateOrCreate(
            ['shm_application_id' => $application->id],
            [
                'type' => ShmMemorandum::TYPE_SHM_SHGB,
                'status' => ShmMemorandum::STATUS_DRAFT,
                'notes' => 'Naskah SHM/SHGB',
                'created_by' => $this->admin->id,
            ]
        );

        $context = ShmDocument::requirementContextFor($application->fresh());

        $this->assertSame(ShmMemorandum::TYPE_SHM_SHGB, $context['current']);
    }

    public function test_requirement_context_reuses_buyback_memorandum_type(): void
    {
        $application = $this->createApplicationFor($this->marketing);
        $application->refresh();

        ShmMemorandum::query()->updateOrCreate(
            ['shm_application_id' => $application->id],
            [
                'type' => ShmMemorandum::TYPE_BUYBACK,
                'status' => ShmMemorandum::STATUS_DRAFT,
                'notes' => 'Naskah Buyback',
                'created_by' => $this->admin->id,
            ]
        );

        $context = ShmDocument::requirementContextFor($application->fresh());

        $this->assertSame(ShmMemorandum::TYPE_BUYBACK, $context['current']);
    }

    public function test_requirement_context_does_not_invent_required_documents(): void
    {
        $application = $this->createApplicationFor($this->marketing);

        $context = ShmDocument::requirementContextFor($application);

        $this->assertArrayNotHasKey('required_documents', $context);
        $this->assertArrayNotHasKey('optional_documents', $context);
        $this->assertArrayNotHasKey('conditional_documents', $context);
    }

    // -----------------------------------------------------------------
    // B. Completeness semantics tidak berubah
    // -----------------------------------------------------------------

    public function test_documents_complete_semantics_unchanged(): void
    {
        $application = $this->createApplicationFor($this->marketing);

        // zero documents => false
        $this->assertFalse($application->documentsComplete());

        $application = $this->advanceToDocumentCheck($this->admin, $application);

        $this->actingAs($this->admin)
            ->put(route('shm.applications.document', $application->id), [
                'document_name' => 'Dokumen A',
                'status' => ShmDocument::STATUS_PENDING,
            ])
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->assertFalse($application->documentsComplete());

        $this->actingAs($this->admin)
            ->put(route('shm.applications.document', $application->id), [
                'document_name' => 'Dokumen A',
                'status' => ShmDocument::STATUS_COMPLETE,
            ])
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->assertTrue($application->documentsComplete());

        $this->actingAs($this->admin)
            ->put(route('shm.applications.document', $application->id), [
                'document_name' => 'Dokumen A',
                'status' => ShmDocument::STATUS_NOT_APPLICABLE,
            ])
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->assertTrue($application->documentsComplete());
    }

    // -----------------------------------------------------------------
    // C. T51 compatibility
    // -----------------------------------------------------------------

    public function test_t51_gate_still_blocks_ready_for_disbursement_with_pending(): void
    {
        $application = $this->createApplicationFor($this->marketing);

        $this->actingAs($this->marketing)
            ->post(route('shm.applications.submit', $application->id))
            ->assertSessionHasNoErrors();
        $application->refresh();

        $this->actingAs($this->marketing)
            ->post(route('shm.applications.credit-checking.start', $application->id))
            ->assertSessionHasNoErrors();
        $application->refresh();

        $this->actingAs($this->marketing)
            ->post(route('shm.applications.start-memorandum', $application->id))
            ->assertSessionHasNoErrors();
        $application->refresh();

        $this->actingAs($this->marketing)
            ->post(route('shm.applications.start-approval', $application->id))
            ->assertSessionHasNoErrors();
        $application->refresh();

        $this->actingAs($this->admin)
            ->post(route('shm.applications.approve', $application->id), ['notes' => 'Layak'])
            ->assertSessionHasNoErrors();
        $application->refresh();

        $this->actingAs($this->admin)
            ->post(route('shm.applications.start-document-check', $application->id))
            ->assertSessionHasNoErrors();
        $application->refresh();

        // dokumen incomplete
        ShmDocument::query()->create([
            'shm_application_id' => $application->id,
            'document_name' => 'Dokumen A',
            'status' => ShmDocument::STATUS_PENDING,
        ]);

        $application->refresh();
        $this->assertFalse($application->documentsComplete());

        $this->actingAs($this->admin)
            ->post(route('shm.applications.mark-ready-for-disbursement', $application->id))
            ->assertSessionHasErrors('status');

        $this->assertSame(ShmApplicationStatus::DocumentCheck, $application->refresh()->status);
    }

    // -----------------------------------------------------------------
    // D. T52 compatibility
    // -----------------------------------------------------------------

    public function test_t52_reopen_still_works_with_taxonomy_present(): void
    {
        $application = $this->createApplicationFor($this->marketing);

        $application = $this->advanceToDocumentCheck($this->admin, $application);

        // satu dokumen lengkap agar boleh ke ready_for_disbursement
        $this->actingAs($this->admin)
            ->put(route('shm.applications.document', $application->id), [
                'document_name' => 'Dokumen A',
                'status' => ShmDocument::STATUS_COMPLETE,
            ])
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->assertTrue($application->documentsComplete());

        $this->actingAs($this->admin)
            ->post(route('shm.applications.mark-ready-for-disbursement', $application->id))
            ->assertSessionHasNoErrors();
        $application->refresh();

        // buat dokumen pending agar incomplete
        ShmDocument::query()->create([
            'shm_application_id' => $application->id,
            'document_name' => 'Dokumen A',
            'status' => ShmDocument::STATUS_PENDING,
        ]);

        $application->refresh();
        $this->assertFalse($application->documentsComplete());

        // pasang memorandum supaya konteks taxonomy tidak pending
        ShmMemorandum::query()->updateOrCreate(
            ['shm_application_id' => $application->id],
            [
                'type' => ShmMemorandum::TYPE_SHM_SHGB,
                'status' => ShmMemorandum::STATUS_DRAFT,
                'notes' => 'Naskah SHM/SHGB',
                'created_by' => $this->admin->id,
            ]
        );

        $this->actingAs($this->admin)
            ->post(route('shm.applications.reopen-document-check', $application->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(ShmApplicationStatus::DocumentCheck, $application->refresh()->status);
    }

    public function test_t52_reopen_rejected_when_documents_complete(): void
    {
        $application = $this->createApplicationFor($this->marketing);

        $application = $this->advanceToDocumentCheck($this->admin, $application);

        // satu dokumen lengkap agar boleh ke ready_for_disbursement
        $this->actingAs($this->admin)
            ->put(route('shm.applications.document', $application->id), [
                'document_name' => 'Dokumen A',
                'status' => ShmDocument::STATUS_COMPLETE,
            ])
            ->assertSessionHasNoErrors();

        $application->refresh();
        $this->assertTrue($application->documentsComplete());

        $this->actingAs($this->admin)
            ->post(route('shm.applications.mark-ready-for-disbursement', $application->id))
            ->assertSessionHasNoErrors();
        $application->refresh();

        ShmDocument::query()->create([
            'shm_application_id' => $application->id,
            'document_name' => 'Dokumen A',
            'status' => ShmDocument::STATUS_COMPLETE,
        ]);

        $this->actingAs($this->admin)
            ->post(route('shm.applications.reopen-document-check', $application->id))
            ->assertSessionHasErrors('status');

        $this->assertSame(ShmApplicationStatus::ReadyForDisbursement, $application->refresh()->status);
    }

    // -----------------------------------------------------------------
    // E. Workflow / status / audit tidak berubah oleh T53
    // -----------------------------------------------------------------

    public function test_no_new_application_status_introduced(): void
    {
        $cases = ShmApplicationStatus::cases();
        $values = array_column($cases, 'value');

        $this->assertNotContains('document_requirement', $values);
        $this->assertNotContains('requirement_context', $values);
    }

    public function test_document_update_still_audited_once(): void
    {
        $application = $this->createApplicationFor($this->marketing);
        $application = $this->advanceToDocumentCheck($this->admin, $application);

        $before = AuditLog::query()->where('object_type', ShmDocument::class)->count();

        $this->actingAs($this->admin)
            ->put(route('shm.applications.document', $application->id), [
                'document_name' => 'Dokumen Baru',
                'status' => ShmDocument::STATUS_COMPLETE,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            $before + 1,
            AuditLog::query()->where('object_type', ShmDocument::class)->count()
        );
    }

    public function test_taxonomy_method_is_read_only_and_does_not_write(): void
    {
        $application = $this->createApplicationFor($this->marketing);

        $before = AuditLog::query()->count();
        ShmDocument::requirementContextFor($application);
        $after = AuditLog::query()->count();

        $this->assertSame($before, $after);
    }
}
