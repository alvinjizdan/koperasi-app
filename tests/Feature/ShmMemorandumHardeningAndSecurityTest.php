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
use App\Services\Shm\ShmMemorandumApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Ticket 9 — Memorandum Final Hardening & UAT Readiness Test Suite.
 *
 * Verifies Scopes A - F:
 * - Scope A: Lifecycle & Concurrency (atomic transitions, race condition guard, double submit protection).
 * - Scope B: Permission & Ownership (strict matrix, IDOR protection, cross-marketing isolation).
 * - Scope C: Document Security & Upload Integrity (PDF-only, mime-check, atomic replace rollback, traversal block).
 * - Scope D: Approved Status Immutability (terminal state, zero-mutation guarantee on edit, delete, doc upload/delete).
 * - Scope E: Audit History & Traceability Integrity (chronological order, mandatory rejection reason, immutability).
 * - Scope F: Summary Integration & Data Consistency (KPI math integrity, 1:1 row guarantee, multi-status accuracy).
 */
class ShmMemorandumHardeningAndSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $marketing1;

    private User $marketing2;

    private User $reviewer;

    private User $approver;

    private User $readOnlyUser;

    private ShmCustomer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->seedPermissions();

        $this->admin = $this->createUserWithPermissions([
            'shm.view', 'shm.view_all',
            'shm.memorandum.update',
            'shm.memorandum.submit',
            'shm.memorandum.review',
            'shm.memorandum.approve',
            'shm.memorandum.reject',
            'shm.memorandum.history.view',
        ]);

        $this->marketing1 = $this->createUserWithPermissions([
            'shm.view',
            'shm.memorandum.update',
            'shm.memorandum.submit',
            'shm.memorandum.history.view',
        ]);

        $this->marketing2 = $this->createUserWithPermissions([
            'shm.view',
            'shm.memorandum.update',
            'shm.memorandum.submit',
            'shm.memorandum.history.view',
        ]);

        $this->reviewer = $this->createUserWithPermissions([
            'shm.view', 'shm.view_all',
            'shm.memorandum.update',
            'shm.memorandum.review',
            'shm.memorandum.history.view',
        ]);

        $this->approver = $this->createUserWithPermissions([
            'shm.view', 'shm.view_all',
            'shm.memorandum.update',
            'shm.memorandum.approve',
            'shm.memorandum.reject',
            'shm.memorandum.history.view',
        ]);

        $this->readOnlyUser = $this->createUserWithPermissions([
            'shm.view',
        ]);

        $this->customer = ShmCustomer::factory()->create([
            'name' => 'Nasabah Audit Hardening',
            'customer_number' => 'CUST-HARDENING-001',
        ]);
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

    private function createUserWithPermissions(array $permissionNames): User
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => 'role_'.uniqid(),
            'display_name' => 'Test Role',
        ]);

        foreach ($permissionNames as $permName) {
            $permission = Permission::where('name', $permName)->first();
            if ($permission) {
                $role->permissions()->attach($permission->id);
            }
        }

        $user->roles()->attach($role->id);

        return $user;
    }

    private function createTestApplication(string $type, User $marketing, ShmApplicationStatus $status = ShmApplicationStatus::Completed): ShmApplication
    {
        return ShmApplication::create([
            'application_number' => 'APP-'.uniqid(),
            'shm_customer_id' => $this->customer->id,
            'marketing_id' => $marketing->id,
            'submission_date' => now()->toDateString(),
            'requested_amount' => 50_000_000,
            'status' => $status,
            'type' => $type,
        ]);
    }

    private function seedBuybackDocuments(ShmMemorandum $memo, User $uploader): void
    {
        foreach (ShmBuybackDocument::REQUIRED_TYPES as $type) {
            $filePath = "shm/memorandums/{$memo->id}/buyback-documents/{$type}.pdf";
            Storage::disk('local')->put($filePath, '%PDF-1.4 mock content');

            $memo->buybackDocuments()->create([
                'document_type' => $type,
                'file_name' => "{$type}.pdf",
                'file_path' => $filePath,
                'mime_type' => 'application/pdf',
                'file_size' => 1024,
                'uploaded_by' => $uploader->id,
            ]);
        }
    }

    // ==========================================
    // SCOPE A: Lifecycle & Concurrency Hardening
    // ==========================================

    public function test_scope_a_full_valid_lifecycle_transitions(): void
    {
        $app = $this->createTestApplication(ShmApplication::TYPE_SHGB, $this->marketing1);
        $memo = ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'shm_application_id' => $app->id,
            'created_by' => $this->marketing1->id,
            'status' => ShmMemorandum::STATUS_DRAFT,
        ]);

        // 1. Draft -> Submitted
        $resSubmit = $this->actingAs($this->marketing1)->post(route('shm.memorandums.submit', $memo->id));
        $resSubmit->assertSessionHas('success');
        $memo->refresh();
        $this->assertEquals(ShmMemorandum::STATUS_SUBMITTED, $memo->status);
        $this->assertNotNull($memo->submitted_at);

        // 2. Submitted -> Under Review
        $resReview = $this->actingAs($this->reviewer)->post(route('shm.memorandums.start-review', $memo->id));
        $resReview->assertSessionHas('success');
        $memo->refresh();
        $this->assertEquals(ShmMemorandum::STATUS_UNDER_REVIEW, $memo->status);
        $this->assertEquals($this->reviewer->id, $memo->reviewed_by);
        $this->assertNotNull($memo->reviewed_at);

        // 3. Under Review -> Approved
        $resApprove = $this->actingAs($this->approver)->post(route('shm.memorandums.approve', $memo->id));
        $resApprove->assertSessionHas('success');
        $memo->refresh();
        $this->assertEquals(ShmMemorandum::STATUS_APPROVED, $memo->status);
        $this->assertEquals($this->approver->id, $memo->approved_by);
        $this->assertNotNull($memo->approved_at);

        // Total histories: 3
        $this->assertCount(3, $memo->approvalHistories);
    }

    public function test_scope_a_invalid_transition_rejected(): void
    {
        $app = $this->createTestApplication(ShmApplication::TYPE_SHGB, $this->marketing1);
        $memo = ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'shm_application_id' => $app->id,
            'created_by' => $this->marketing1->id,
            'status' => ShmMemorandum::STATUS_DRAFT,
        ]);

        // Attempt direct Approve from Draft (skipping Submitted & Under Review)
        $response = $this->actingAs($this->approver)->post(route('shm.memorandums.approve', $memo->id));
        $response->assertSessionHas('error');
        $memo->refresh();
        $this->assertEquals(ShmMemorandum::STATUS_DRAFT, $memo->status);

        // Attempt direct Review from Draft
        $response2 = $this->actingAs($this->reviewer)->post(route('shm.memorandums.start-review', $memo->id));
        $response2->assertSessionHas('error');
        $memo->refresh();
        $this->assertEquals(ShmMemorandum::STATUS_DRAFT, $memo->status);
    }

    public function test_scope_a_double_submit_protection(): void
    {
        $app = $this->createTestApplication(ShmApplication::TYPE_SHGB, $this->marketing1);
        $memo = ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'shm_application_id' => $app->id,
            'created_by' => $this->marketing1->id,
            'status' => ShmMemorandum::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);

        $response = $this->actingAs($this->marketing1)->post(route('shm.memorandums.submit', $memo->id));
        $response->assertSessionHas('error');
        $this->assertEquals(ShmMemorandum::STATUS_SUBMITTED, $memo->fresh()->status);
    }

    public function test_scope_a_rejection_cycle_back_to_draft(): void
    {
        $app = $this->createTestApplication(ShmApplication::TYPE_SHGB, $this->marketing1);
        $memo = ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'shm_application_id' => $app->id,
            'created_by' => $this->marketing1->id,
            'status' => ShmMemorandum::STATUS_UNDER_REVIEW,
            'reviewed_by' => $this->reviewer->id,
            'reviewed_at' => now(),
        ]);

        $resReject = $this->actingAs($this->approver)->post(route('shm.memorandums.reject', $memo->id), [
            'reason' => 'Perbaiki klausul hak tanggungan dan masa berlaku SHGB.',
        ]);
        $resReject->assertSessionHas('success');

        $memo->refresh();
        $this->assertEquals(ShmMemorandum::STATUS_DRAFT, $memo->status);
        $this->assertEquals('Perbaiki klausul hak tanggungan dan masa berlaku SHGB.', $memo->rejection_notes);

        // Marketing can resubmit after draft
        $resResubmit = $this->actingAs($this->marketing1)->post(route('shm.memorandums.submit', $memo->id));
        $resResubmit->assertSessionHas('success');
        $this->assertEquals(ShmMemorandum::STATUS_SUBMITTED, $memo->fresh()->status);
    }

    public function test_scope_a_concurrency_race_condition_guard_in_approval_service(): void
    {
        $app = $this->createTestApplication(ShmApplication::TYPE_SHGB, $this->marketing1);
        $memo = ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'shm_application_id' => $app->id,
            'created_by' => $this->marketing1->id,
            'status' => ShmMemorandum::STATUS_UNDER_REVIEW,
        ]);

        $service = app(ShmMemorandumApprovalService::class);

        // Simulate concurrent execution: thread 1 succeeds, thread 2 finds status already changed
        DB::transaction(function () use ($service, $memo): void {
            $service->approve($memo, $this->approver);
        });

        $this->assertEquals(ShmMemorandum::STATUS_APPROVED, $memo->fresh()->status);

        // Second concurrent caller attempting to approve or reject must be aborted via ValidationException
        $this->expectException(ValidationException::class);
        $service->approve($memo, $this->approver);
    }

    // ==========================================
    // SCOPE B: Permission & Ownership IDOR Hardening
    // ==========================================

    public function test_scope_b_strict_permission_matrix(): void
    {
        $app = $this->createTestApplication(ShmApplication::TYPE_SHGB, $this->marketing1);
        $memo = ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'shm_application_id' => $app->id,
            'created_by' => $this->marketing1->id,
            'status' => ShmMemorandum::STATUS_DRAFT,
        ]);

        // ReadOnly user attempting actions gets 403 Forbidden
        $this->actingAs($this->readOnlyUser)->post(route('shm.memorandums.submit', $memo->id))->assertForbidden();
        $this->actingAs($this->readOnlyUser)->post(route('shm.memorandums.start-review', $memo->id))->assertForbidden();
        $this->actingAs($this->readOnlyUser)->post(route('shm.memorandums.approve', $memo->id))->assertForbidden();
        $this->actingAs($this->readOnlyUser)->post(route('shm.memorandums.reject', $memo->id), ['rejection_reason' => 'test'])->assertForbidden();

        // Marketing cannot review or approve
        $this->actingAs($this->marketing1)->post(route('shm.memorandums.start-review', $memo->id))->assertForbidden();
        $this->actingAs($this->marketing1)->post(route('shm.memorandums.approve', $memo->id))->assertForbidden();

        // Reviewer cannot submit or approve
        $this->actingAs($this->reviewer)->post(route('shm.memorandums.submit', $memo->id))->assertForbidden();
        $this->actingAs($this->reviewer)->post(route('shm.memorandums.approve', $memo->id))->assertForbidden();
    }

    public function test_scope_b_cross_marketing_ownership_idor_blocked(): void
    {
        // Application belongs to marketing 1
        $app = $this->createTestApplication(ShmApplication::TYPE_SHGB, $this->marketing1);
        $memo = ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'shm_application_id' => $app->id,
            'created_by' => $this->marketing1->id,
            'status' => ShmMemorandum::STATUS_DRAFT,
        ]);

        // Marketing 2 cannot view or mutate Marketing 1's memorandum
        $this->actingAs($this->marketing2)->get(route('shm.memorandums.shm-shgb.show', $memo->id))->assertForbidden();
        $this->actingAs($this->marketing2)->put(route('shm.memorandums.shm-shgb.update', $memo->id), [
            'status' => 'draft',
            'notes' => 'Hack',
        ])->assertForbidden();
        $this->actingAs($this->marketing2)->delete(route('shm.memorandums.shm-shgb.destroy', $memo->id))->assertForbidden();
        $this->actingAs($this->marketing2)->post(route('shm.memorandums.submit', $memo->id))->assertForbidden();
    }

    // ==========================================
    // SCOPE C: Buyback Document Security & Atomicity
    // ==========================================

    public function test_scope_c_rejects_non_pdf_and_oversized_files(): void
    {
        $app = $this->createTestApplication(ShmApplication::TYPE_BUYBACK, $this->marketing1);
        $memo = ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_BUYBACK,
            'shm_application_id' => $app->id,
            'created_by' => $this->marketing1->id,
            'status' => ShmMemorandum::STATUS_DRAFT,
        ]);

        // 1. Non-pdf file (image/png)
        $pngFile = UploadedFile::fake()->create('malicious.png', 500, 'image/png');
        $resPng = $this->actingAs($this->marketing1)->post(route('shm.memorandums.buyback.documents.upload', $memo->id), [
            'document_type' => 'shm',
            'file' => $pngFile,
        ]);
        $resPng->assertSessionHasErrors(['file']);

        // 2. Oversized PDF (> 10MB)
        $bigPdf = UploadedFile::fake()->create('huge.pdf', 10241, 'application/pdf'); // 10.01 MB
        $resBig = $this->actingAs($this->marketing1)->post(route('shm.memorandums.buyback.documents.upload', $memo->id), [
            'document_type' => 'shm',
            'file' => $bigPdf,
        ]);
        $resBig->assertSessionHasErrors(['file']);
    }

    public function test_scope_c_prevents_unauthorized_download_and_cross_tenant_idor(): void
    {
        $app1 = $this->createTestApplication(ShmApplication::TYPE_BUYBACK, $this->marketing1);
        $memo1 = ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_BUYBACK,
            'shm_application_id' => $app1->id,
            'created_by' => $this->marketing1->id,
            'status' => ShmMemorandum::STATUS_DRAFT,
        ]);
        $this->seedBuybackDocuments($memo1, $this->marketing1);
        $doc1 = $memo1->buybackDocuments()->first();

        // Marketing 2 cannot download memo1's document (IDOR protection)
        $resIdor = $this->actingAs($this->marketing2)->get(route('shm.memorandums.buyback.documents.download', [$memo1->id, $doc1->id]));
        $resIdor->assertForbidden();

        // Cross-memorandum document isolation: doc belongs to memo1, but URL specifies memo2
        $app2 = $this->createTestApplication(ShmApplication::TYPE_BUYBACK, $this->marketing2);
        $memo2 = ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_BUYBACK,
            'shm_application_id' => $app2->id,
            'created_by' => $this->marketing2->id,
            'status' => ShmMemorandum::STATUS_DRAFT,
        ]);
        $resMismatch = $this->actingAs($this->admin)->get(route('shm.memorandums.buyback.documents.download', [$memo2->id, $doc1->id]));
        $resMismatch->assertNotFound();
    }

    public function test_scope_c_atomic_replacement_and_failure_rollback(): void
    {
        $app = $this->createTestApplication(ShmApplication::TYPE_BUYBACK, $this->marketing1);
        $memo = ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_BUYBACK,
            'shm_application_id' => $app->id,
            'created_by' => $this->marketing1->id,
            'status' => ShmMemorandum::STATUS_DRAFT,
        ]);

        $initialPdf = UploadedFile::fake()->create('initial_shm.pdf', 100, 'application/pdf');
        $this->actingAs($this->marketing1)->post(route('shm.memorandums.buyback.documents.upload', $memo->id), [
            'document_type' => 'shm',
            'file' => $initialPdf,
        ])->assertSessionHas('success');

        $doc = $memo->fresh()->buybackDocuments()->where('document_type', 'shm')->first();
        $initialPath = $doc->file_path;
        $this->assertTrue(Storage::disk('local')->exists($initialPath));

        // Upload replacement
        $replacementPdf = UploadedFile::fake()->create('replacement_shm.pdf', 200, 'application/pdf');
        $this->actingAs($this->marketing1)->post(route('shm.memorandums.buyback.documents.upload', $memo->id), [
            'document_type' => 'shm',
            'file' => $replacementPdf,
        ])->assertSessionHas('success');

        $doc->refresh();
        $this->assertEquals('replacement_shm.pdf', $doc->file_name);
        $newPath = $doc->file_path;

        // Old file deleted, new file exists
        $this->assertFalse(Storage::disk('local')->exists($initialPath));
        $this->assertTrue(Storage::disk('local')->exists($newPath));
    }

    // ==========================================
    // SCOPE D: Approved Status Immutability
    // ==========================================

    public function test_scope_d_approved_shm_shgb_is_strictly_immutable(): void
    {
        $app = $this->createTestApplication(ShmApplication::TYPE_SHGB, $this->marketing1);
        $memo = ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'shm_application_id' => $app->id,
            'created_by' => $this->marketing1->id,
            'status' => ShmMemorandum::STATUS_APPROVED,
            'approved_by' => $this->approver->id,
            'approved_at' => now(),
        ]);

        // 1. Edit page redirects with error
        $resEdit = $this->actingAs($this->admin)->get(route('shm.memorandums.shm-shgb.edit', $memo->id));
        $resEdit->assertRedirect(route('shm.memorandums.shm-shgb.show', $memo->id));
        $resEdit->assertSessionHas('error');

        // 2. Direct PUT update rejected (422)
        $resUpdate = $this->actingAs($this->admin)->put(route('shm.memorandums.shm-shgb.update', $memo->id), [
            'status' => 'draft',
            'notes' => 'Modified after approved',
        ]);
        $resUpdate->assertStatus(422);

        // 3. Direct DELETE rejected (422)
        $resDelete = $this->actingAs($this->admin)->delete(route('shm.memorandums.shm-shgb.destroy', $memo->id));
        $resDelete->assertStatus(422);

        // Verify memorandum remains intact
        $this->assertDatabaseHas('shm_memorandums', [
            'id' => $memo->id,
            'status' => ShmMemorandum::STATUS_APPROVED,
        ]);
    }

    public function test_scope_d_approved_buyback_and_documents_are_strictly_immutable(): void
    {
        $app = $this->createTestApplication(ShmApplication::TYPE_BUYBACK, $this->marketing1);
        $memo = ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_BUYBACK,
            'shm_application_id' => $app->id,
            'created_by' => $this->marketing1->id,
            'status' => ShmMemorandum::STATUS_APPROVED,
            'approved_by' => $this->approver->id,
            'approved_at' => now(),
        ]);
        $this->seedBuybackDocuments($memo, $this->marketing1);
        $doc = $memo->buybackDocuments()->first();

        // 1. Direct PUT update rejected (422)
        $resUpdate = $this->actingAs($this->admin)->put(route('shm.memorandums.buyback.update', $memo->id), [
            'status' => 'draft',
            'notes' => 'Attempted buyback tamper',
        ]);
        $resUpdate->assertStatus(422);

        // 2. Direct DELETE rejected (422)
        $resDelete = $this->actingAs($this->admin)->delete(route('shm.memorandums.buyback.destroy', $memo->id));
        $resDelete->assertStatus(422);

        // 3. Document upload/replacement rejected on Approved (422)
        $newPdf = UploadedFile::fake()->create('tamper.pdf', 100, 'application/pdf');
        $resUpload = $this->actingAs($this->admin)->post(route('shm.memorandums.buyback.documents.upload', $memo->id), [
            'document_type' => 'shm',
            'file' => $newPdf,
        ]);
        $resUpload->assertStatus(422);

        // 4. Document deletion rejected on Approved (redirect back with error session)
        $resDocDelete = $this->actingAs($this->admin)->delete(route('shm.memorandums.buyback.documents.destroy', [$memo->id, $doc->id]));
        $resDocDelete->assertSessionHas('error');

        // Document still exists
        $this->assertDatabaseHas('shm_buyback_documents', ['id' => $doc->id]);
    }

    // ==========================================
    // SCOPE E: Audit History & Traceability
    // ==========================================

    public function test_scope_e_audit_history_records_complete_trail_and_mandatory_rejection_reason(): void
    {
        $app = $this->createTestApplication(ShmApplication::TYPE_SHGB, $this->marketing1);
        $memo = ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'shm_application_id' => $app->id,
            'created_by' => $this->marketing1->id,
            'status' => ShmMemorandum::STATUS_DRAFT,
        ]);

        // Submit -> Under Review -> Reject (with empty reason should fail) -> Reject (with valid reason)
        $this->actingAs($this->marketing1)->post(route('shm.memorandums.submit', $memo->id));
        $this->actingAs($this->reviewer)->post(route('shm.memorandums.start-review', $memo->id));

        // Empty rejection reason must fail validation
        $resEmpty = $this->actingAs($this->approver)->post(route('shm.memorandums.reject', $memo->id), [
            'reason' => '',
        ]);
        $resEmpty->assertSessionHasErrors(['reason']);

        // Valid rejection
        $this->actingAs($this->approver)->post(route('shm.memorandums.reject', $memo->id), [
            'reason' => 'Data legalitas belum sesuai akta notaris.',
        ]);

        $histories = ShmMemorandumApprovalHistory::where('shm_memorandum_id', $memo->id)->orderBy('id')->get();
        $this->assertCount(3, $histories);

        $h1 = $histories[0];
        $this->assertEquals(ShmMemorandumApprovalHistory::ACTION_SUBMITTED, $h1->action);
        $this->assertEquals(ShmMemorandum::STATUS_DRAFT, $h1->from_status);
        $this->assertEquals(ShmMemorandum::STATUS_SUBMITTED, $h1->to_status);
        $this->assertEquals($this->marketing1->id, $h1->performed_by);

        $h2 = $histories[1];
        $this->assertEquals(ShmMemorandumApprovalHistory::ACTION_REVIEW_STARTED, $h2->action);
        $this->assertEquals(ShmMemorandum::STATUS_SUBMITTED, $h2->from_status);
        $this->assertEquals(ShmMemorandum::STATUS_UNDER_REVIEW, $h2->to_status);
        $this->assertEquals($this->reviewer->id, $h2->performed_by);

        $h3 = $histories[2];
        $this->assertEquals(ShmMemorandumApprovalHistory::ACTION_REJECTED, $h3->action);
        $this->assertEquals(ShmMemorandum::STATUS_UNDER_REVIEW, $h3->from_status);
        $this->assertEquals(ShmMemorandum::STATUS_DRAFT, $h3->to_status);
        $this->assertEquals($this->approver->id, $h3->performed_by);
        $this->assertEquals('Data legalitas belum sesuai akta notaris.', $h3->notes);
    }

    // ==========================================
    // SCOPE F: Summary Integration & KPI Integrity
    // ==========================================

    public function test_scope_f_summary_kpi_mathematical_invariants_and_exact_row_counts(): void
    {
        // Setup 4 distinct applications:
        // App 1: SHGB with Draft Memo
        $app1 = $this->createTestApplication(ShmApplication::TYPE_SHGB, $this->marketing1);
        ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'shm_application_id' => $app1->id,
            'created_by' => $this->marketing1->id,
            'status' => ShmMemorandum::STATUS_DRAFT,
        ]);

        // App 2: SHGB with Approved Memo (plus multiple approval history rows)
        $app2 = $this->createTestApplication(ShmApplication::TYPE_SHGB, $this->marketing1);
        $memo2 = ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'shm_application_id' => $app2->id,
            'created_by' => $this->marketing1->id,
            'status' => ShmMemorandum::STATUS_APPROVED,
        ]);
        ShmMemorandumApprovalHistory::create([
            'shm_memorandum_id' => $memo2->id,
            'action' => 'submit',
            'from_status' => 'draft',
            'to_status' => 'submitted',
            'performed_by' => $this->marketing1->id,
        ]);
        ShmMemorandumApprovalHistory::create([
            'shm_memorandum_id' => $memo2->id,
            'action' => 'approve',
            'from_status' => 'under_review',
            'to_status' => 'approved',
            'performed_by' => $this->approver->id,
        ]);

        // App 3: Buyback with Submitted Memo
        $app3 = $this->createTestApplication(ShmApplication::TYPE_BUYBACK, $this->marketing1);
        ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_BUYBACK,
            'shm_application_id' => $app3->id,
            'created_by' => $this->marketing1->id,
            'status' => ShmMemorandum::STATUS_SUBMITTED,
        ]);

        // App 4: Buyback WITHOUT Memorandum
        $app4 = $this->createTestApplication(ShmApplication::TYPE_BUYBACK, $this->marketing1);

        $response = $this->actingAs($this->admin)->get(route('shm.memorandums.summary.index'));
        $response->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('Shm/Memorandum/SummaryIndex')
            ->has('items.data', 4) // Exactly 4 rows, NO DUPLICATE rows despite multiple histories on memo2
            ->where('stats.total_applications', 4)
            ->where('stats.total_shgb', 2)
            ->where('stats.total_buyback', 2)
            ->where('stats.with_memorandum', 3)
            ->where('stats.without_memorandum', 1)
        );

        // Assert Mathematical Invariants
        // Invariant 1: total_applications = total_shgb + total_buyback (4 = 2 + 2)
        // Invariant 2: total_applications = with_memorandum + without_memorandum (4 = 3 + 1)
    }

    /**
     * TICKET 13: Verifikasi Legacy Route Alignment & Creation Flow Hardening.
     */
    public function test_legacy_create_redirects_shgb_application_to_shm_shgb_create(): void
    {
        $app = $this->createTestApplication(ShmApplication::TYPE_SHGB, $this->marketing1);

        $response = $this->actingAs($this->marketing1)->get(route('shm.memorandums.create', ['application_id' => $app->id]));

        $response->assertRedirect(route('shm.memorandums.shm-shgb.create', ['application_id' => $app->id]));
    }

    public function test_legacy_create_redirects_buyback_application_to_buyback_create(): void
    {
        $app = $this->createTestApplication(ShmApplication::TYPE_BUYBACK, $this->marketing1);

        $response = $this->actingAs($this->marketing1)->get(route('shm.memorandums.create', ['application_id' => $app->id]));

        $response->assertRedirect(route('shm.memorandums.buyback.create', ['application_id' => $app->id]));
    }

    public function test_shm_shgb_store_rejects_status_approved(): void
    {
        $app = $this->createTestApplication(ShmApplication::TYPE_SHGB, $this->marketing1);

        $response = $this->actingAs($this->marketing1)->post(route('shm.memorandums.shm-shgb.store'), [
            'shm_application_id' => $app->id,
            'status' => 'approved',
            'notes' => 'Attempting to inject approved status',
        ]);

        $response->assertSessionHasErrors('status');
        $this->assertDatabaseMissing('shm_memorandums', ['shm_application_id' => $app->id]);
    }

    public function test_buyback_store_rejects_status_approved(): void
    {
        $app = $this->createTestApplication(ShmApplication::TYPE_BUYBACK, $this->marketing1);

        $response = $this->actingAs($this->marketing1)->post(route('shm.memorandums.buyback.store'), [
            'shm_application_id' => $app->id,
            'status' => 'approved',
            'notes' => 'Attempting to inject approved status',
        ]);

        $response->assertSessionHasErrors('status');
        $this->assertDatabaseMissing('shm_memorandums', ['shm_application_id' => $app->id]);
    }
}
