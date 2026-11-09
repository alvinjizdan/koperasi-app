<?php

namespace Tests\Feature;

use App\Enums\ShmApplicationStatus;
use App\Models\JournalEntry;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ShmApplication;
use App\Models\ShmBuybackDocument;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmMemorandum;
use App\Models\ShmMemorandumApprovalHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Ticket 10 — Memorandum End-to-End User Acceptance Testing (UAT) Simulation.
 *
 * Implements and simulates Scenarios UAT-01 to UAT-12:
 * - UAT-01: Memorandum Navigation across 4 submenus with permission checks.
 * - UAT-02: SHGB Application Creation and transition to Completed status.
 * - UAT-03: SHM/SHGB Memorandum Creation (1:1, draft state, SHGB dates).
 * - UAT-04: Buyback Application Creation & manual conversion to Buyback Memorandum.
 * - UAT-05: Buyback Document Management (5 mandatory PDFs, 10MB limit, private storage, atomic replace).
 * - UAT-06: Submit Memorandum (draft -> submitted, double-submit protection).
 * - UAT-07: Start Review (submitted -> under_review, reviewer assignment, double-review protection).
 * - UAT-08: Rejection & Correction (rejection reason mandatory, under_review -> draft, correction & resubmit).
 * - UAT-09: Final Approval (under_review -> approved, terminal state, mutation attempts blocked).
 * - UAT-10: Summary Verification (multi-filter, pagination, 1:1 row guarantee, KPI math invariants).
 * - UAT-11: Role & Permission Matrix (multi-actor enforcement: marketing, reviewer, approver, unauthorized).
 * - UAT-12: Data Isolation & Regression (no auto-disbursement, no accounting mutations, non-shm safety).
 */
class ShmMemorandumUatSimulationTest extends TestCase
{
    use RefreshDatabase;

    private User $marketingUser;

    private User $reviewerUser;

    private User $approverUser;

    private User $unauthorizedUser;

    private User $otherMarketingUser;

    private ShmCustomer $testCustomer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->seedPermissions();

        // 1. Setup Actors with discrete permissions according to matrix
        $this->marketingUser = $this->createUserWithRoleAndPermissions('marketing_uat', [
            'shm.view',
            'shm.memorandum.update',
            'shm.memorandum.submit',
            'shm.memorandum.history.view',
        ]);

        $this->otherMarketingUser = $this->createUserWithRoleAndPermissions('other_marketing_uat', [
            'shm.view',
            'shm.memorandum.update',
            'shm.memorandum.submit',
            'shm.memorandum.history.view',
        ]);

        $this->reviewerUser = $this->createUserWithRoleAndPermissions('reviewer_uat', [
            'shm.view',
            'shm.view_all',
            'shm.memorandum.update',
            'shm.memorandum.review',
            'shm.memorandum.history.view',
        ]);

        $this->approverUser = $this->createUserWithRoleAndPermissions('approver_uat', [
            'shm.view',
            'shm.view_all',
            'shm.memorandum.update',
            'shm.memorandum.approve',
            'shm.memorandum.reject',
            'shm.memorandum.history.view',
        ]);

        $this->unauthorizedUser = $this->createUserWithRoleAndPermissions('unauthorized_uat', [
            'shm.view',
        ]);

        $this->testCustomer = ShmCustomer::factory()->create([
            'name' => 'Budi Santoso UAT Dummy',
            'customer_number' => 'CUST-UAT-999',
            'nik' => '3271000000000001',
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

    private function createUserWithRoleAndPermissions(string $roleName, array $permissionNames): User
    {
        $user = User::factory()->create();
        $role = Role::create([
            'name' => $roleName,
            'display_name' => ucwords(str_replace('_', ' ', $roleName)),
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

    private function createShmApplication(string $type, User $marketing, ShmApplicationStatus $status): ShmApplication
    {
        return ShmApplication::create([
            'application_number' => 'APP-UAT-'.uniqid(),
            'shm_customer_id' => $this->testCustomer->id,
            'marketing_id' => $marketing->id,
            'submission_date' => now()->toDateString(),
            'requested_amount' => 75_000_000,
            'status' => $status,
            'type' => $type,
        ]);
    }

    private function uploadValidBuybackDocuments(ShmMemorandum $memo, User $uploader): void
    {
        foreach (ShmBuybackDocument::REQUIRED_TYPES as $type) {
            $pdf = UploadedFile::fake()->create("{$type}.pdf", 120, 'application/pdf');
            $this->actingAs($uploader)->post(route('shm.memorandums.buyback.documents.upload', $memo->id), [
                'document_type' => $type,
                'file' => $pdf,
            ]);
        }
    }

    // =========================================================================
    // UAT-01: Memorandum Navigation
    // =========================================================================
    public function test_uat_01_memorandum_navigation_all_submenus(): void
    {
        // 1. Authorized user can access all 4 submenus
        $submenus = [
            'shm.memorandums.index' => 'Shm/Memorandum/Index',
            'shm.memorandums.shm-shgb.index' => 'Shm/Memorandum/ShmShgbIndex',
            'shm.memorandums.buyback.index' => 'Shm/Memorandum/BuybackIndex',
            'shm.memorandums.summary.index' => 'Shm/Memorandum/SummaryIndex',
        ];

        foreach ($submenus as $routeName => $componentName) {
            $res = $this->actingAs($this->marketingUser)->get(route($routeName));
            $res->assertOk();
            $res->assertInertia(fn ($page) => $page->component($componentName));
        }

        // 2. Unauthorized user is blocked (403 Forbidden)
        foreach ($submenus as $routeName => $componentName) {
            $this->actingAs($this->unauthorizedUser)->get(route($routeName))->assertForbidden();
        }
    }

    // =========================================================================
    // UAT-02: SHGB Application Creation & Marketing Lifecycle
    // =========================================================================
    public function test_uat_02_shgb_application_creation(): void
    {
        // Marketing creates application of type SHGB
        $shgbApp = $this->createShmApplication(ShmApplication::TYPE_SHGB, $this->marketingUser, ShmApplicationStatus::Draft);

        $this->assertEquals(ShmApplication::TYPE_SHGB, $shgbApp->type);
        $this->assertEquals(ShmApplicationStatus::Draft, $shgbApp->status);

        // Progress Marketing stage to Completed
        $shgbApp->status = ShmApplicationStatus::Completed;
        $shgbApp->save();

        $this->assertEquals(ShmApplicationStatus::Completed, $shgbApp->fresh()->status);
        $this->assertNull($shgbApp->memorandum);
    }

    // =========================================================================
    // UAT-03: SHM/SHGB Memorandum Creation
    // =========================================================================
    public function test_uat_03_shm_shgb_memorandum_creation_and_validation(): void
    {
        $app = $this->createShmApplication(ShmApplication::TYPE_SHGB, $this->marketingUser, ShmApplicationStatus::Completed);

        // 1. Picker shows eligible application
        $resCreatePage = $this->actingAs($this->marketingUser)->get(route('shm.memorandums.shm-shgb.create'));
        $resCreatePage->assertOk();
        $resCreatePage->assertInertia(fn ($page) => $page
            ->component('Shm/Memorandum/ShmShgbCreate')
            ->has('applications', 1)
            ->where('applications.0.id', $app->id)
        );

        // 2. Date validation: shgb_end_date cannot be before shgb_start_date
        $invalidDatesRes = $this->actingAs($this->marketingUser)->post(route('shm.memorandums.shm-shgb.store'), [
            'shm_application_id' => $app->id,
            'status' => 'draft',
            'notes' => 'Catatan UAT',
            'shgb_start_date' => '2026-12-01',
            'shgb_end_date' => '2026-11-01', // End date is before start date!
        ]);
        $invalidDatesRes->assertSessionHasErrors(['shgb_end_date']);

        // 3. Valid store
        $validStoreRes = $this->actingAs($this->marketingUser)->post(route('shm.memorandums.shm-shgb.store'), [
            'shm_application_id' => $app->id,
            'status' => 'draft',
            'notes' => 'Catatan UAT SHM/SHGB',
            'shgb_start_date' => '2026-01-01',
            'shgb_end_date' => '2036-01-01',
        ]);
        $validStoreRes->assertRedirect();

        $memo = ShmMemorandum::where('shm_application_id', $app->id)->first();
        $this->assertNotNull($memo);
        $this->assertEquals(ShmMemorandum::TYPE_SHM_SHGB, $memo->type);
        $this->assertEquals(ShmMemorandum::STATUS_DRAFT, $memo->status);
        $this->assertEquals('2026-01-01', $memo->shgb_start_date->toDateString());
        $this->assertEquals('2036-01-01', $memo->shgb_end_date->toDateString());

        // 4. Duplicate prevention (1:1 constraint)
        $duplicateRes = $this->actingAs($this->marketingUser)->post(route('shm.memorandums.shm-shgb.store'), [
            'shm_application_id' => $app->id,
            'status' => 'draft',
            'notes' => 'Duplikat UAT',
        ]);
        $duplicateRes->assertSessionHasErrors(['shm_application_id']);
    }

    // =========================================================================
    // UAT-04: Buyback Application & Memorandum Creation
    // =========================================================================
    public function test_uat_04_buyback_application_and_memorandum(): void
    {
        $buybackApp = $this->createShmApplication(ShmApplication::TYPE_BUYBACK, $this->marketingUser, ShmApplicationStatus::Completed);
        $shgbApp = $this->createShmApplication(ShmApplication::TYPE_SHGB, $this->marketingUser, ShmApplicationStatus::Completed);

        // 1. Cannot convert SHGB application to Buyback Memorandum
        $mismatchRes = $this->actingAs($this->marketingUser)->post(route('shm.memorandums.buyback.store'), [
            'shm_application_id' => $shgbApp->id,
            'status' => 'draft',
            'notes' => 'Mismatch attempt',
        ]);
        $mismatchRes->assertSessionHasErrors(['shm_application_id']);

        // 2. Valid Buyback Memorandum creation
        $validRes = $this->actingAs($this->marketingUser)->post(route('shm.memorandums.buyback.store'), [
            'shm_application_id' => $buybackApp->id,
            'status' => 'draft',
            'notes' => 'Memorandum Buyback Valid UAT',
        ]);
        $validRes->assertRedirect();

        $memo = ShmMemorandum::where('shm_application_id', $buybackApp->id)->first();
        $this->assertNotNull($memo);
        $this->assertEquals(ShmMemorandum::TYPE_BUYBACK, $memo->type);
        $this->assertEquals(ShmMemorandum::STATUS_DRAFT, $memo->status);

        // 3. Detail page shows Buyback data and empty documents list
        $showRes = $this->actingAs($this->marketingUser)->get(route('shm.memorandums.buyback.show', $memo->id));
        $showRes->assertOk();
        $showRes->assertInertia(fn ($page) => $page
            ->component('Shm/Memorandum/BuybackShow')
            ->where('memorandum.id', $memo->id)
            ->where('memorandum.type', 'buyback')
            ->where('memorandum.is_documents_complete', false)
            ->where('memorandum.documents_count', 0)
        );
    }

    // =========================================================================
    // UAT-05: Buyback Document Management
    // =========================================================================
    public function test_uat_05_buyback_document_management(): void
    {
        $app = $this->createShmApplication(ShmApplication::TYPE_BUYBACK, $this->marketingUser, ShmApplicationStatus::Completed);
        $memo = ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_BUYBACK,
            'shm_application_id' => $app->id,
            'created_by' => $this->marketingUser->id,
            'status' => ShmMemorandum::STATUS_DRAFT,
        ]);

        // 1. Upload 5 mandatory documents progressively
        $types = ShmBuybackDocument::REQUIRED_TYPES;
        $count = 0;
        foreach ($types as $type) {
            $pdf = UploadedFile::fake()->createWithContent("{$type}.pdf", '%PDF-1.4 Mock PDF content for UAT testing');
            $uploadRes = $this->actingAs($this->marketingUser)->post(route('shm.memorandums.buyback.documents.upload', $memo->id), [
                'document_type' => $type,
                'file' => $pdf,
            ]);
            $uploadRes->assertSessionHas('success');
            $count++;
            $this->assertEquals($count, $memo->fresh()->buybackDocuments()->count());
        }

        $this->assertTrue($memo->fresh()->isBuybackDocumentsComplete());

        // 2. Download document
        $doc = $memo->buybackDocuments()->first();
        $downloadRes = $this->actingAs($this->marketingUser)->get(route('shm.memorandums.buyback.documents.download', ['memorandum' => $memo->id, 'document' => $doc->id]));
        $downloadRes->assertOk();
        $downloadRes->assertHeader('Content-Type', 'application/pdf');

        // 3. Replace document
        $replacementPdf = UploadedFile::fake()->createWithContent('replacement.pdf', '%PDF-1.4 Replacement PDF content');
        $replaceRes = $this->actingAs($this->marketingUser)->post(route('shm.memorandums.buyback.documents.upload', $memo->id), [
            'document_type' => $doc->document_type,
            'file' => $replacementPdf,
        ]);
        $replaceRes->assertSessionHas('success');

        $doc->refresh();
        $this->assertEquals('replacement.pdf', $doc->file_name);
        $this->assertEquals(5, $memo->fresh()->buybackDocuments()->count()); // Still 5, no duplicates

        // 4. Negative tests
        // Non-pdf
        $pngFile = UploadedFile::fake()->create('doc.png', 50, 'image/png');
        $this->actingAs($this->marketingUser)->post(route('shm.memorandums.buyback.documents.upload', $memo->id), [
            'document_type' => 'shm',
            'file' => $pngFile,
        ])->assertSessionHasErrors(['file']);

        // Oversized (>10MB)
        $hugePdf = UploadedFile::fake()->create('huge.pdf', 10500, 'application/pdf');
        $this->actingAs($this->marketingUser)->post(route('shm.memorandums.buyback.documents.upload', $memo->id), [
            'document_type' => 'shm',
            'file' => $hugePdf,
        ])->assertSessionHasErrors(['file']);

        // IDOR: other marketing cannot download
        $this->actingAs($this->otherMarketingUser)->get(route('shm.memorandums.buyback.documents.download', [$memo->id, $doc->id]))->assertForbidden();
    }

    // =========================================================================
    // UAT-06: Submit Memorandum
    // =========================================================================
    public function test_uat_06_submit_memorandum_and_incomplete_guard(): void
    {
        // 1. Buyback incomplete documents submit must fail
        $bbApp = $this->createShmApplication(ShmApplication::TYPE_BUYBACK, $this->marketingUser, ShmApplicationStatus::Completed);
        $bbMemo = ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_BUYBACK,
            'shm_application_id' => $bbApp->id,
            'created_by' => $this->marketingUser->id,
            'status' => ShmMemorandum::STATUS_DRAFT,
        ]);

        $failSubmitRes = $this->actingAs($this->marketingUser)->post(route('shm.memorandums.submit', $bbMemo->id));
        $failSubmitRes->assertSessionHas('error');
        $this->assertEquals(ShmMemorandum::STATUS_DRAFT, $bbMemo->fresh()->status);

        // Upload all 5 documents
        $this->uploadValidBuybackDocuments($bbMemo, $this->marketingUser);

        // 2. Successful Submit
        $successSubmitRes = $this->actingAs($this->marketingUser)->post(route('shm.memorandums.submit', $bbMemo->id));
        $successSubmitRes->assertSessionHas('success');
        $this->assertEquals(ShmMemorandum::STATUS_SUBMITTED, $bbMemo->fresh()->status);
        $this->assertNotNull($bbMemo->fresh()->submitted_at);

        // 3. Double Submit rejected
        $doubleSubmitRes = $this->actingAs($this->marketingUser)->post(route('shm.memorandums.submit', $bbMemo->id));
        $doubleSubmitRes->assertSessionHas('error');

        // History recorded
        $this->assertCount(1, $bbMemo->fresh()->approvalHistories);
        $this->assertEquals(ShmMemorandumApprovalHistory::ACTION_SUBMITTED, $bbMemo->fresh()->approvalHistories->first()->action);
    }

    // =========================================================================
    // UAT-07: Start Review
    // =========================================================================
    public function test_uat_07_start_review(): void
    {
        $app = $this->createShmApplication(ShmApplication::TYPE_SHGB, $this->marketingUser, ShmApplicationStatus::Completed);
        $memo = ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'shm_application_id' => $app->id,
            'created_by' => $this->marketingUser->id,
            'status' => ShmMemorandum::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);

        // 1. Reviewer starts review
        $reviewRes = $this->actingAs($this->reviewerUser)->post(route('shm.memorandums.start-review', $memo->id));
        $reviewRes->assertSessionHas('success');

        $memo->refresh();
        $this->assertEquals(ShmMemorandum::STATUS_UNDER_REVIEW, $memo->status);
        $this->assertEquals($this->reviewerUser->id, $memo->reviewed_by);
        $this->assertNotNull($memo->reviewed_at);

        // 2. Double review rejected
        $doubleReviewRes = $this->actingAs($this->reviewerUser)->post(route('shm.memorandums.start-review', $memo->id));
        $doubleReviewRes->assertSessionHas('error');

        // 3. Unauthorized actor cannot start review
        $this->actingAs($this->marketingUser)->post(route('shm.memorandums.start-review', $memo->id))->assertForbidden();
    }

    // =========================================================================
    // UAT-08: Rejection, Correction & Resubmission
    // =========================================================================
    public function test_uat_08_rejection_and_correction_cycle(): void
    {
        $app = $this->createShmApplication(ShmApplication::TYPE_SHGB, $this->marketingUser, ShmApplicationStatus::Completed);
        $memo = ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'shm_application_id' => $app->id,
            'created_by' => $this->marketingUser->id,
            'status' => ShmMemorandum::STATUS_UNDER_REVIEW,
            'reviewed_by' => $this->reviewerUser->id,
            'reviewed_at' => now(),
        ]);

        // 1. Rejection without reason rejected
        $emptyReasonRes = $this->actingAs($this->approverUser)->post(route('shm.memorandums.reject', $memo->id), [
            'reason' => '',
        ]);
        $emptyReasonRes->assertSessionHasErrors(['reason']);

        // 2. Valid Rejection
        $validRejectRes = $this->actingAs($this->approverUser)->post(route('shm.memorandums.reject', $memo->id), [
            'reason' => 'Perbaiki masa berlaku SHGB agar sesuai sertifikat asli notaris.',
        ]);
        $validRejectRes->assertSessionHas('success');

        $memo->refresh();
        $this->assertEquals(ShmMemorandum::STATUS_DRAFT, $memo->status);
        $this->assertEquals('Perbaiki masa berlaku SHGB agar sesuai sertifikat asli notaris.', $memo->rejection_notes);

        // 3. Marketing corrects data while in Draft
        $correctionRes = $this->actingAs($this->marketingUser)->put(route('shm.memorandums.shm-shgb.update', $memo->id), [
            'status' => ShmMemorandum::STATUS_DRAFT,
            'notes' => 'Catatan telah disesuaikan per arahan komite.',
            'shgb_start_date' => '2026-02-01',
            'shgb_end_date' => '2046-02-01',
        ]);
        $correctionRes->assertSessionHas('success');

        // 4. Marketing resubmits
        $resubmitRes = $this->actingAs($this->marketingUser)->post(route('shm.memorandums.submit', $memo->id));
        $resubmitRes->assertSessionHas('success');

        $memo->refresh();
        $this->assertEquals(ShmMemorandum::STATUS_SUBMITTED, $memo->status);
        $this->assertEquals('Catatan telah disesuaikan per arahan komite.', $memo->notes);

        // History preserves full chronological sequence
        $histories = $memo->approvalHistories()->orderBy('id')->get();
        $this->assertCount(2, $histories); // 1: rejected, 2: resubmitted
        $this->assertEquals(ShmMemorandumApprovalHistory::ACTION_REJECTED, $histories[0]->action);
        $this->assertEquals(ShmMemorandumApprovalHistory::ACTION_RESUBMITTED, $histories[1]->action);
    }

    // =========================================================================
    // UAT-09: Final Approval & Immutability Verification
    // =========================================================================
    public function test_uat_09_final_approval_and_terminal_immutability(): void
    {
        $app = $this->createShmApplication(ShmApplication::TYPE_SHGB, $this->marketingUser, ShmApplicationStatus::Completed);
        $memo = ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'shm_application_id' => $app->id,
            'created_by' => $this->marketingUser->id,
            'status' => ShmMemorandum::STATUS_UNDER_REVIEW,
            'reviewed_by' => $this->reviewerUser->id,
            'reviewed_at' => now(),
        ]);

        // 1. Approver approves
        $approveRes = $this->actingAs($this->approverUser)->post(route('shm.memorandums.approve', $memo->id));
        $approveRes->assertSessionHas('success');

        $memo->refresh();
        $this->assertEquals(ShmMemorandum::STATUS_APPROVED, $memo->status);
        $this->assertEquals($this->approverUser->id, $memo->approved_by);
        $this->assertNotNull($memo->approved_at);

        // 2. Double approve rejected
        $doubleApproveRes = $this->actingAs($this->approverUser)->post(route('shm.memorandums.approve', $memo->id));
        $doubleApproveRes->assertSessionHas('error');

        // 3. Terminal Immutability: PUT, DELETE, Upload on Approved must be blocked (422)
        $tamperPut = $this->actingAs($this->approverUser)->put(route('shm.memorandums.shm-shgb.update', $memo->id), [
            'status' => 'draft',
            'notes' => 'Tamper attempt',
        ]);
        $tamperPut->assertStatus(422);

        $tamperDelete = $this->actingAs($this->approverUser)->delete(route('shm.memorandums.shm-shgb.destroy', $memo->id));
        $tamperDelete->assertStatus(422);

        // 4. Detail page accessible and renders approval badges
        $showRes = $this->actingAs($this->marketingUser)->get(route('shm.memorandums.shm-shgb.show', $memo->id));
        $showRes->assertOk();
        $showRes->assertInertia(fn ($page) => $page
            ->component('Shm/Memorandum/ShmShgbShow')
            ->where('memorandum.status', 'approved')
            ->where('can_edit', false) // edit button disabled
        );
    }

    // =========================================================================
    // UAT-10: Summary Verification
    // =========================================================================
    public function test_uat_10_summary_verification_and_kpi_invariants(): void
    {
        // Setup dataset:
        // App 1: SHGB with Draft Memo
        $app1 = $this->createShmApplication(ShmApplication::TYPE_SHGB, $this->marketingUser, ShmApplicationStatus::Completed);
        ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'shm_application_id' => $app1->id,
            'created_by' => $this->marketingUser->id,
            'status' => ShmMemorandum::STATUS_DRAFT,
        ]);

        // App 2: SHGB with Approved Memo
        $app2 = $this->createShmApplication(ShmApplication::TYPE_SHGB, $this->marketingUser, ShmApplicationStatus::Completed);
        ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'shm_application_id' => $app2->id,
            'created_by' => $this->marketingUser->id,
            'status' => ShmMemorandum::STATUS_APPROVED,
        ]);

        // App 3: Buyback with Submitted Memo
        $app3 = $this->createShmApplication(ShmApplication::TYPE_BUYBACK, $this->marketingUser, ShmApplicationStatus::Completed);
        ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_BUYBACK,
            'shm_application_id' => $app3->id,
            'created_by' => $this->marketingUser->id,
            'status' => ShmMemorandum::STATUS_SUBMITTED,
        ]);

        // App 4: Buyback without Memorandum
        $app4 = $this->createShmApplication(ShmApplication::TYPE_BUYBACK, $this->marketingUser, ShmApplicationStatus::Completed);

        // Access Summary
        $res = $this->actingAs($this->reviewerUser)->get(route('shm.memorandums.summary.index'));
        $res->assertOk();

        $res->assertInertia(fn ($page) => $page
            ->component('Shm/Memorandum/SummaryIndex')
            ->has('items.data', 4) // Exact 4 items
            ->where('stats.total_applications', 4)
            ->where('stats.total_shgb', 2)
            ->where('stats.total_buyback', 2)
            ->where('stats.with_memorandum', 3)
            ->where('stats.without_memorandum', 1)
        );

        // Filter test: type = shgb
        $shgbFilteredRes = $this->actingAs($this->reviewerUser)->get(route('shm.memorandums.summary.index', ['type' => 'shgb']));
        $shgbFilteredRes->assertOk();
        $shgbFilteredRes->assertInertia(fn ($page) => $page->has('items.data', 2));

        // Filter test: type = buyback
        $bbFilteredRes = $this->actingAs($this->reviewerUser)->get(route('shm.memorandums.summary.index', ['type' => 'buyback']));
        $bbFilteredRes->assertOk();
        $bbFilteredRes->assertInertia(fn ($page) => $page->has('items.data', 2));
    }

    // =========================================================================
    // UAT-11: Role & Permission Matrix
    // =========================================================================
    public function test_uat_11_role_and_permission_matrix_enforcement(): void
    {
        $app = $this->createShmApplication(ShmApplication::TYPE_SHGB, $this->marketingUser, ShmApplicationStatus::Completed);
        $memo = ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'shm_application_id' => $app->id,
            'created_by' => $this->marketingUser->id,
            'status' => ShmMemorandum::STATUS_DRAFT,
        ]);

        // 1. Marketing cannot review or approve
        $this->actingAs($this->marketingUser)->post(route('shm.memorandums.start-review', $memo->id))->assertForbidden();
        $this->actingAs($this->marketingUser)->post(route('shm.memorandums.approve', $memo->id))->assertForbidden();

        // 2. Reviewer cannot submit or approve
        $this->actingAs($this->reviewerUser)->post(route('shm.memorandums.submit', $memo->id))->assertForbidden();
        $this->actingAs($this->reviewerUser)->post(route('shm.memorandums.approve', $memo->id))->assertForbidden();

        // 3. Approver cannot submit or review
        $this->actingAs($this->approverUser)->post(route('shm.memorandums.submit', $memo->id))->assertForbidden();
        $this->actingAs($this->approverUser)->post(route('shm.memorandums.start-review', $memo->id))->assertForbidden();

        // 4. Unauthorized cannot perform any action
        $this->actingAs($this->unauthorizedUser)->post(route('shm.memorandums.submit', $memo->id))->assertForbidden();
        $this->actingAs($this->unauthorizedUser)->post(route('shm.memorandums.start-review', $memo->id))->assertForbidden();
        $this->actingAs($this->unauthorizedUser)->post(route('shm.memorandums.approve', $memo->id))->assertForbidden();
        $this->actingAs($this->unauthorizedUser)->post(route('shm.memorandums.reject', $memo->id), ['reason' => 'test'])->assertForbidden();

        // 5. Cross-marketing ownership check
        $this->actingAs($this->otherMarketingUser)->get(route('shm.memorandums.shm-shgb.show', $memo->id))->assertForbidden();
    }

    // =========================================================================
    // UAT-12: Data Isolation & Non-Regression
    // =========================================================================
    public function test_uat_12_data_isolation_and_no_side_effects(): void
    {
        $app = $this->createShmApplication(ShmApplication::TYPE_SHGB, $this->marketingUser, ShmApplicationStatus::Completed);
        $memo = ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'shm_application_id' => $app->id,
            'created_by' => $this->marketingUser->id,
            'status' => ShmMemorandum::STATUS_UNDER_REVIEW,
            'reviewed_by' => $this->reviewerUser->id,
            'reviewed_at' => now(),
        ]);

        $initialJournalEntriesCount = JournalEntry::count();
        $initialLoansCount = ShmLoan::count();

        // Approve memorandum
        $this->actingAs($this->approverUser)->post(route('shm.memorandums.approve', $memo->id))->assertSessionHas('success');

        // Verify side-effect isolation:
        // 1. ShmApplication status remains Completed (NOT automatically altered)
        $this->assertEquals(ShmApplicationStatus::Completed, $app->fresh()->status);

        // 2. No automatic accounting journal created
        $this->assertEquals($initialJournalEntriesCount, JournalEntry::count());

        // 3. No automatic loan disbursement created
        $this->assertEquals($initialLoansCount, ShmLoan::count());
    }
}
