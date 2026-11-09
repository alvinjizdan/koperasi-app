<?php

namespace Tests\Feature;

use App\Enums\ShmApplicationStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ShmApplication;
use App\Models\ShmBuybackDocument;
use App\Models\ShmCustomer;
use App\Models\ShmMemorandum;
use App\Models\ShmMemorandumApprovalHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ShmMemorandumApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $marketing;

    private User $reviewer;

    private User $approver;

    private User $unauthorizedUser;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->seedPermissions();

        $this->marketing = $this->createUserWithPermissions(['shm.memorandum.update', 'shm.memorandum.submit', 'shm.view']);
        $this->reviewer = $this->createUserWithPermissions(['shm.memorandum.update', 'shm.memorandum.review', 'shm.view_all']);
        $this->approver = $this->createUserWithPermissions(['shm.memorandum.update', 'shm.memorandum.approve', 'shm.memorandum.reject', 'shm.view_all']);
        $this->unauthorizedUser = $this->createUserWithPermissions(['shm.view']);
    }

    private function seedPermissions(): void
    {
        $permissions = [
            'shm.view',
            'shm.view_all',
            'shm.memorandum.update',
            'shm.memorandum.submit',
            'shm.memorandum.review',
            'shm.memorandum.approve',
            'shm.memorandum.reject',
            'shm.memorandum.history.view',
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(
                ['name' => $perm],
                ['module' => 'shm', 'action' => $perm, 'display_name' => $perm]
            );
        }
    }

    private function createUserWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'role_'.uniqid(),
            'display_name' => 'Test Role',
        ]);

        foreach ($permissions as $permName) {
            $permission = Permission::where('name', $permName)->first();
            if ($permission) {
                $role->permissions()->attach($permission->id);
            }
        }

        $user->roles()->attach($role->id);

        return $user;
    }

    private function createCompletedApplication(string $type, User $marketing): ShmApplication
    {
        $customer = ShmCustomer::factory()->create();

        return ShmApplication::create([
            'application_number' => 'APP-'.uniqid(),
            'shm_customer_id' => $customer->id,
            'marketing_id' => $marketing->id,
            'type' => $type,
            'status' => ShmApplicationStatus::Completed,
            'completed_at' => now(),
        ]);
    }

    public function test_shm_shgb_approval_lifecycle_draft_to_approved(): void
    {
        $app = $this->createCompletedApplication(ShmApplication::TYPE_SHGB, $this->marketing);

        $memo = ShmMemorandum::create([
            'shm_application_id' => $app->id,
            'created_by' => $this->marketing->id,
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'status' => ShmMemorandum::STATUS_DRAFT,
            'notes' => 'Draft naskah SHM/SHGB',
            'shgb_start_date' => '2026-01-01',
            'shgb_end_date' => '2046-01-01',
        ]);

        // 1. Submit (Draft -> Submitted)
        $submitResponse = $this->actingAs($this->marketing)
            ->post(route('shm.memorandums.submit', $memo->id), [
                'notes' => 'Mengajukan naskah',
            ]);

        $submitResponse->assertSessionHas('success');
        $memo->refresh();
        $this->assertEquals(ShmMemorandum::STATUS_SUBMITTED, $memo->status);
        $this->assertEquals($this->marketing->id, $memo->submitted_by);
        $this->assertNotNull($memo->submitted_at);

        $this->assertDatabaseHas('shm_memorandum_approval_histories', [
            'shm_memorandum_id' => $memo->id,
            'action' => ShmMemorandumApprovalHistory::ACTION_SUBMITTED,
            'from_status' => ShmMemorandum::STATUS_DRAFT,
            'to_status' => ShmMemorandum::STATUS_SUBMITTED,
            'performed_by' => $this->marketing->id,
        ]);

        // 2. Start Review (Submitted -> Under Review)
        $reviewResponse = $this->actingAs($this->reviewer)
            ->post(route('shm.memorandums.start-review', $memo->id), [
                'notes' => 'Memulai pemeriksaan kelengkapan',
            ]);

        $reviewResponse->assertSessionHas('success');
        $memo->refresh();
        $this->assertEquals(ShmMemorandum::STATUS_UNDER_REVIEW, $memo->status);
        $this->assertEquals($this->reviewer->id, $memo->reviewed_by);
        $this->assertNotNull($memo->reviewed_at);

        $this->assertDatabaseHas('shm_memorandum_approval_histories', [
            'shm_memorandum_id' => $memo->id,
            'action' => ShmMemorandumApprovalHistory::ACTION_REVIEW_STARTED,
            'from_status' => ShmMemorandum::STATUS_SUBMITTED,
            'to_status' => ShmMemorandum::STATUS_UNDER_REVIEW,
            'performed_by' => $this->reviewer->id,
        ]);

        // 3. Approve (Under Review -> Approved)
        $approveResponse = $this->actingAs($this->approver)
            ->post(route('shm.memorandums.approve', $memo->id), [
                'notes' => 'Semua klausul disetujui',
            ]);

        $approveResponse->assertSessionHas('success');
        $memo->refresh();
        $this->assertEquals(ShmMemorandum::STATUS_APPROVED, $memo->status);
        $this->assertEquals($this->approver->id, $memo->approved_by);
        $this->assertNotNull($memo->approved_at);

        $this->assertDatabaseHas('shm_memorandum_approval_histories', [
            'shm_memorandum_id' => $memo->id,
            'action' => ShmMemorandumApprovalHistory::ACTION_APPROVED,
            'from_status' => ShmMemorandum::STATUS_UNDER_REVIEW,
            'to_status' => ShmMemorandum::STATUS_APPROVED,
            'performed_by' => $this->approver->id,
        ]);

        // Memastikan riwayat audit berurutan
        $this->assertCount(3, $memo->approvalHistories);
    }

    public function test_rejection_lifecycle_returns_to_draft_with_mandatory_reason_and_resubmit(): void
    {
        $app = $this->createCompletedApplication(ShmApplication::TYPE_SHGB, $this->marketing);

        $memo = ShmMemorandum::create([
            'shm_application_id' => $app->id,
            'created_by' => $this->marketing->id,
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'status' => ShmMemorandum::STATUS_UNDER_REVIEW,
            'submitted_by' => $this->marketing->id,
            'submitted_at' => now(),
            'reviewed_by' => $this->reviewer->id,
            'reviewed_at' => now(),
        ]);

        // Validasi reason penolakan wajib
        $emptyReasonResponse = $this->actingAs($this->approver)
            ->post(route('shm.memorandums.reject', $memo->id), [
                'reason' => '',
            ]);
        $emptyReasonResponse->assertSessionHasErrors(['reason']);

        // Penolakan valid
        $rejectResponse = $this->actingAs($this->approver)
            ->post(route('shm.memorandums.reject', $memo->id), [
                'reason' => 'Klausul jaminan pada pasal 4 belum jelas.',
            ]);

        $rejectResponse->assertSessionHas('success');
        $memo->refresh();
        $this->assertEquals(ShmMemorandum::STATUS_DRAFT, $memo->status);
        $this->assertEquals('Klausul jaminan pada pasal 4 belum jelas.', $memo->rejection_notes);
        $this->assertNotNull($memo->rejected_at);
        // Active submitted & reviewed metadata di-reset
        $this->assertNull($memo->submitted_by);
        $this->assertNull($memo->reviewed_by);

        $this->assertDatabaseHas('shm_memorandum_approval_histories', [
            'shm_memorandum_id' => $memo->id,
            'action' => ShmMemorandumApprovalHistory::ACTION_REJECTED,
            'from_status' => ShmMemorandum::STATUS_UNDER_REVIEW,
            'to_status' => ShmMemorandum::STATUS_DRAFT,
            'notes' => 'Klausul jaminan pada pasal 4 belum jelas.',
            'performed_by' => $this->approver->id,
        ]);

        // Resubmit setelah perbaikan
        $resubmitResponse = $this->actingAs($this->marketing)
            ->post(route('shm.memorandums.submit', $memo->id), [
                'notes' => 'Pasal 4 sudah diperbaiki sesuai catatan.',
            ]);

        $resubmitResponse->assertSessionHas('success');
        $memo->refresh();
        $this->assertEquals(ShmMemorandum::STATUS_SUBMITTED, $memo->status);

        $this->assertDatabaseHas('shm_memorandum_approval_histories', [
            'shm_memorandum_id' => $memo->id,
            'action' => ShmMemorandumApprovalHistory::ACTION_RESUBMITTED,
            'from_status' => ShmMemorandum::STATUS_DRAFT,
            'to_status' => ShmMemorandum::STATUS_SUBMITTED,
            'performed_by' => $this->marketing->id,
        ]);
    }

    public function test_buyback_memorandum_requires_all_5_documents_before_submit(): void
    {
        $app = $this->createCompletedApplication(ShmApplication::TYPE_BUYBACK, $this->marketing);

        $memo = ShmMemorandum::create([
            'shm_application_id' => $app->id,
            'created_by' => $this->marketing->id,
            'type' => ShmMemorandum::TYPE_BUYBACK,
            'status' => ShmMemorandum::STATUS_DRAFT,
        ]);

        // Hanya upload 4 dokumen dari 5
        $types = ['shm', 'akta_buyback', 'kuasa_awal', 'ppjb'];
        foreach ($types as $type) {
            ShmBuybackDocument::create([
                'shm_memorandum_id' => $memo->id,
                'document_type' => $type,
                'file_name' => "{$type}.pdf",
                'file_path' => "shm/docs/{$type}.pdf",
                'mime_type' => 'application/pdf',
                'file_size' => 1024,
                'uploaded_by' => $this->marketing->id,
            ]);
        }

        // Coba submit saat dokumen 4/5
        $response = $this->actingAs($this->marketing)
            ->post(route('shm.memorandums.submit', $memo->id));

        $response->assertSessionHas('error');
        $memo->refresh();
        $this->assertEquals(ShmMemorandum::STATUS_DRAFT, $memo->status);

        // Upload dokumen ke-5 ('pengosongan')
        ShmBuybackDocument::create([
            'shm_memorandum_id' => $memo->id,
            'document_type' => 'pengosongan',
            'file_name' => 'pengosongan.pdf',
            'file_path' => 'shm/docs/pengosongan.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1024,
            'uploaded_by' => $this->marketing->id,
        ]);

        // Submit ulang saat sudah 5/5
        $successResponse = $this->actingAs($this->marketing)
            ->post(route('shm.memorandums.submit', $memo->id));

        $successResponse->assertSessionHas('success');
        $memo->refresh();
        $this->assertEquals(ShmMemorandum::STATUS_SUBMITTED, $memo->status);
    }

    public function test_unauthorized_users_are_forbidden_from_approval_actions(): void
    {
        $app = $this->createCompletedApplication(ShmApplication::TYPE_SHGB, $this->marketing);

        $memo = ShmMemorandum::create([
            'shm_application_id' => $app->id,
            'created_by' => $this->marketing->id,
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'status' => ShmMemorandum::STATUS_SUBMITTED,
        ]);

        // User tanpa shm.memorandum.review tidak boleh startReview
        $this->actingAs($this->unauthorizedUser)
            ->post(route('shm.memorandums.start-review', $memo->id))
            ->assertForbidden();

        // User tanpa shm.memorandum.approve tidak boleh approve
        $this->actingAs($this->unauthorizedUser)
            ->post(route('shm.memorandums.approve', $memo->id))
            ->assertForbidden();

        // User tanpa shm.memorandum.reject tidak boleh reject
        $this->actingAs($this->unauthorizedUser)
            ->post(route('shm.memorandums.reject', $memo->id), ['reason' => 'Coba tolak'])
            ->assertForbidden();
    }
}
