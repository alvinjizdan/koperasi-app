<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\ShmApplication;
use App\Models\ShmBuybackDocument;
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
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CreditCheckingWorkflowEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private User $direkturUtama;

    private User $superadmin;

    private User $marketing;

    private User $creditAnalyst;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(MakroPhysicalRoleSeeder::class);
        $this->seed(MakroSystemRoleSeeder::class);

        $dirutRole = Role::where('name', 'direktur_utama_makro')->first() ?? Role::where('name', 'direktur_utama')->firstOrFail();
        $superadminRole = Role::where('name', 'superadmin')->firstOrFail();
        $marketingRole = Role::where('name', 'marketing_makro')->first() ?? Role::where('name', 'marketing')->firstOrFail();
        $caRole = Role::where('name', 'admin_makro')->first() ?? Role::where('name', 'admin')->firstOrFail();

        $this->direkturUtama = User::factory()->create();
        $this->direkturUtama->roles()->attach($dirutRole->id);

        $this->superadmin = User::factory()->create();
        $this->superadmin->roles()->attach($superadminRole->id);

        $this->marketing = User::factory()->create();
        $this->marketing->roles()->attach($marketingRole->id);

        $this->creditAnalyst = User::factory()->create();
        $this->creditAnalyst->roles()->attach($caRole->id);
    }

    private function makeApplication(string $type = 'shgb'): ShmApplication
    {
        $customer = ShmCustomer::factory()->create();

        return ShmApplication::create([
            'application_number' => 'APP-TEST-'.uniqid(),
            'shm_customer_id' => $customer->id,
            'marketing_id' => $this->marketing->id,
            'type' => $type,
            'status' => 'credit_checking',
        ]);
    }

    public function test_superadmin_cannot_authorize_exception_without_explicit_permission(): void
    {
        $application = $this->makeApplication();
        $service = app(ShmCreditCheckExceptionService::class);

        $this->expectException(HttpException::class);

        $service->authorizeException(
            $application,
            ShmCreditCheckException::TYPE_FAIL_OVERRIDE,
            $this->superadmin,
            'Justifikasi mitigasi internal oleh Superadmin yang sangat lengkap.',
            'Ringkasan resmi untuk disclosure pimpinan.',
            'Hasil credit check FAIL'
        );
    }

    public function test_direktur_utama_can_authorize_exception_with_valid_justification(): void
    {
        $application = $this->makeApplication();
        ShmCreditCheck::create([
            'shm_application_id' => $application->id,
            'filled_by' => $this->creditAnalyst->id,
            'status' => 'completed',
            'outcome' => 'fail',
            'result' => 'Riwayat SLIK DPK 5 macet total',
            'started_at' => now()->subDay(),
            'checked_at' => now(),
        ]);

        $service = app(ShmCreditCheckExceptionService::class);

        $exception = $service->authorizeException(
            $application,
            ShmCreditCheckException::TYPE_FAIL_OVERRIDE,
            $this->direkturUtama,
            'Justifikasi mitigasi risiko kuat atas persetujuan Direktur Utama.',
            'Ringkasan resmi disclosure Direktur Utama.',
            'Hasil credit check FAIL'
        );

        $this->assertInstanceOf(ShmCreditCheckException::class, $exception);
        $this->assertSame(ShmCreditCheckException::TYPE_FAIL_OVERRIDE, $exception->exception_type);
        $this->assertSame(ShmCreditCheckException::STATUS_AUTHORIZED, $exception->status);
        $this->assertSame($this->direkturUtama->id, $exception->authorized_by);
        $this->assertNull($exception->consumed_at);
    }

    public function test_exception_authorization_fails_if_justification_too_short(): void
    {
        $application = $this->makeApplication();
        ShmCreditCheck::create([
            'shm_application_id' => $application->id,
            'filled_by' => $this->creditAnalyst->id,
            'status' => 'completed',
            'outcome' => 'fail',
            'result' => 'Riwayat SLIK DPK 5 macet total',
            'started_at' => now()->subDay(),
            'checked_at' => now(),
        ]);

        $service = app(ShmCreditCheckExceptionService::class);

        $this->expectException(ValidationException::class);

        $service->authorizeException(
            $application,
            ShmCreditCheckException::TYPE_FAIL_OVERRIDE,
            $this->direkturUtama,
            'Terlalu pendek',
            'Ringkasan resmi',
            'Kondisi blocking'
        );
    }

    public function test_cannot_authorize_second_active_exception_for_same_application(): void
    {
        $application = $this->makeApplication();
        ShmCreditCheck::create([
            'shm_application_id' => $application->id,
            'filled_by' => $this->creditAnalyst->id,
            'status' => 'completed',
            'outcome' => 'fail',
            'result' => 'Riwayat SLIK DPK 5 macet total',
            'started_at' => now()->subDay(),
            'checked_at' => now(),
        ]);

        $service = app(ShmCreditCheckExceptionService::class);

        $service->authorizeException(
            $application,
            ShmCreditCheckException::TYPE_FAIL_OVERRIDE,
            $this->direkturUtama,
            'Justifikasi mitigasi pertama yang sangat valid dan detail.',
            'Ringkasan resmi mitigasi pertama.',
            'Kondisi blocking 1'
        );

        $this->expectException(ValidationException::class);

        $service->authorizeException(
            $application,
            ShmCreditCheckException::TYPE_FAIL_OVERRIDE,
            $this->direkturUtama,
            'Justifikasi mitigasi kedua yang mencoba menumpuk izin aktif.',
            'Ringkasan resmi mitigasi kedua.',
            'Kondisi blocking 2'
        );
    }

    public function test_workflow_start_memorandum_blocked_if_credit_check_incomplete_and_no_bypass(): void
    {
        $application = $this->makeApplication();
        $workflowService = app(ShmWorkflowService::class);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Credit Checking belum selesai');

        $workflowService->startMemorandum($application, $this->creditAnalyst->id);
    }

    public function test_workflow_start_memorandum_succeeds_with_completion_bypass(): void
    {
        $application = $this->makeApplication();
        $exceptionService = app(ShmCreditCheckExceptionService::class);
        $workflowService = app(ShmWorkflowService::class);

        $exceptionService->authorizeException(
            $application,
            ShmCreditCheckException::TYPE_COMPLETION_BYPASS,
            $this->direkturUtama,
            'Dispensasi bypass kelengkapan credit checking khusus debitur institusi B2B.',
            'Ringkasan resmi bypass kelengkapan.',
            'Credit check belum selesai'
        );

        $appResult = $workflowService->startMemorandum($application, $this->creditAnalyst->id);

        $this->assertInstanceOf(ShmApplication::class, $appResult);
        $this->assertSame('memorandum', $appResult->status->value);
    }

    public function test_workflow_start_memorandum_blocked_if_outcome_is_fail_and_no_override(): void
    {
        $application = $this->makeApplication();
        ShmCreditCheck::create([
            'shm_application_id' => $application->id,
            'filled_by' => $this->creditAnalyst->id,
            'status' => 'completed',
            'outcome' => 'fail',
            'result' => 'Riwayat SLIK DPK 5 macet total',
            'started_at' => now()->subDay(),
            'checked_at' => now(),
        ]);

        $workflowService = app(ShmWorkflowService::class);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('FAIL');

        $workflowService->startMemorandum($application, $this->creditAnalyst->id);
    }

    public function test_workflow_start_memorandum_succeeds_if_outcome_is_fail_with_authorized_override(): void
    {
        $application = $this->makeApplication();
        $check = ShmCreditCheck::create([
            'shm_application_id' => $application->id,
            'filled_by' => $this->creditAnalyst->id,
            'status' => 'completed',
            'outcome' => 'fail',
            'result' => 'Riwayat SLIK DPK 5 macet total',
            'started_at' => now()->subDay(),
            'checked_at' => now(),
        ]);

        $exceptionService = app(ShmCreditCheckExceptionService::class);
        $exceptionService->authorizeException(
            $application,
            ShmCreditCheckException::TYPE_FAIL_OVERRIDE,
            $this->direkturUtama,
            'Disetujui override FAIL dengan penambahan jaminan deposito likuid 150%.',
            'Ringkasan resmi override FAIL dengan agunan tunai.',
            'Hasil credit check FAIL'
        );

        $workflowService = app(ShmWorkflowService::class);
        $appResult = $workflowService->startMemorandum($application, $this->creditAnalyst->id);

        $this->assertInstanceOf(ShmApplication::class, $appResult);
        $this->assertSame('memorandum', $appResult->status->value);
    }

    public function test_submission_with_outcome_pass_creates_revision_1_and_freezes_snapshot(): void
    {
        $application = $this->makeApplication();
        $check = ShmCreditCheck::create([
            'shm_application_id' => $application->id,
            'filled_by' => $this->creditAnalyst->id,
            'status' => 'completed',
            'outcome' => 'pass',
            'result' => 'Sempurna, kolektibilitas lancar tanpa tunggakan.',
            'started_at' => now()->subDay(),
            'checked_at' => now(),
        ]);

        $memorandum = ShmMemorandum::create([
            'shm_application_id' => $application->id,
            'created_by' => $this->creditAnalyst->id,
            'status' => ShmMemorandum::STATUS_DRAFT,
            'notes' => 'Catatan telaah memorandum.',
            'shm_credit_check_id' => $check->id,
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
        ]);

        $approvalService = app(ShmMemorandumApprovalService::class);
        $submitted = $approvalService->submit($memorandum, $this->creditAnalyst);

        $this->assertInstanceOf(ShmMemorandum::class, $submitted);
        $memorandum->refresh();

        $this->assertSame(ShmMemorandum::STATUS_SUBMITTED, $memorandum->status);
        $this->assertNotNull($memorandum->first_submitted_at);
        $this->assertTrue($memorandum->isSnapshotFrozen());

        // Verifikasi ShmMemorandumSubmission
        $submission = ShmMemorandumSubmission::where('shm_memorandum_id', $memorandum->id)->first();
        $this->assertNotNull($submission);
        $this->assertSame(1, $submission->revision_number);
        $this->assertSame('pass', $submission->credit_check_snapshot['credit_check']['outcome']);
        $this->assertNotEmpty($submission->content_hash);
        $this->assertSame(
            ShmMemorandumSubmission::computeContentHash($submission->toArray()),
            $submission->content_hash
        );
    }

    public function test_submission_with_outcome_review_requires_risk_mitigation_notes(): void
    {
        $application = $this->makeApplication();
        $check = ShmCreditCheck::create([
            'shm_application_id' => $application->id,
            'filled_by' => $this->creditAnalyst->id,
            'status' => 'completed',
            'outcome' => 'review',
            'result' => 'Pernah ada DPK 2 dua tahun lalu namun sudah lunas.',
            'started_at' => now()->subDay(),
            'checked_at' => now(),
        ]);

        $memorandum = ShmMemorandum::create([
            'shm_application_id' => $application->id,
            'created_by' => $this->creditAnalyst->id,
            'status' => ShmMemorandum::STATUS_DRAFT,
            'notes' => null,
            'shm_credit_check_id' => $check->id,
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
        ]);

        $approvalService = app(ShmMemorandumApprovalService::class);

        // Harus gagal jika tidak ada mitigasi
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('REVIEW');

        $approvalService->submit($memorandum, $this->creditAnalyst);
    }

    public function test_submission_with_outcome_review_succeeds_when_risk_mitigation_provided(): void
    {
        $application = $this->makeApplication();
        $check = ShmCreditCheck::create([
            'shm_application_id' => $application->id,
            'filled_by' => $this->creditAnalyst->id,
            'status' => 'completed',
            'outcome' => 'review',
            'result' => 'Pernah ada DPK 2 dua tahun lalu namun sudah lunas.',
            'started_at' => now()->subDay(),
            'checked_at' => now(),
        ]);

        $memorandum = ShmMemorandum::create([
            'shm_application_id' => $application->id,
            'created_by' => $this->creditAnalyst->id,
            'status' => ShmMemorandum::STATUS_DRAFT,
            'notes' => 'Mitigasi: Debitur menyerahkan rekening koran 6 bulan terakhir dengan cashflow stabil.',
            'shm_credit_check_id' => $check->id,
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
        ]);

        $approvalService = app(ShmMemorandumApprovalService::class);
        $submitted = $approvalService->submit($memorandum, $this->creditAnalyst);

        $this->assertInstanceOf(ShmMemorandum::class, $submitted);
        $memorandum->refresh();
        $this->assertSame(ShmMemorandum::STATUS_SUBMITTED, $memorandum->status);

        $submission = ShmMemorandumSubmission::where('shm_memorandum_id', $memorandum->id)->first();
        $this->assertSame('review', $submission->credit_check_snapshot['credit_check']['outcome']);
        $this->assertStringContainsString('Mitigasi', $submission->risk_mitigation_notes);
    }

    public function test_submission_consumes_fail_override_exception_atomically(): void
    {
        $application = $this->makeApplication();
        $check = ShmCreditCheck::create([
            'shm_application_id' => $application->id,
            'filled_by' => $this->creditAnalyst->id,
            'status' => 'completed',
            'outcome' => 'fail',
            'result' => 'Ditolak sistem karena skor kredit rendah.',
            'started_at' => now()->subDay(),
            'checked_at' => now(),
        ]);

        $exceptionService = app(ShmCreditCheckExceptionService::class);
        $exception = $exceptionService->authorizeException(
            $application,
            ShmCreditCheckException::TYPE_FAIL_OVERRIDE,
            $this->direkturUtama,
            'Persetujuan override direksi dengan pertimbangan debitur VIP pemilik aset besar.',
            'Ringkasan resmi override direksi.',
            'Hasil credit check FAIL'
        );

        $memorandum = ShmMemorandum::create([
            'shm_application_id' => $application->id,
            'created_by' => $this->creditAnalyst->id,
            'status' => ShmMemorandum::STATUS_DRAFT,
            'notes' => 'Pengajuan naskah override.',
            'shm_credit_check_id' => $check->id,
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
        ]);

        $approvalService = app(ShmMemorandumApprovalService::class);
        $submitted = $approvalService->submit($memorandum, $this->creditAnalyst);

        $this->assertInstanceOf(ShmMemorandum::class, $submitted);
        $exception->refresh();

        $this->assertSame(ShmCreditCheckException::STATUS_CONSUMED, $exception->status);
        $this->assertNotNull($exception->consumed_at);
        $this->assertSame($memorandum->id, $exception->consumed_memorandum_id);

        $submission = ShmMemorandumSubmission::where('shm_memorandum_id', $memorandum->id)->first();
        $this->assertNotNull($submission->exception_snapshot);
        $this->assertSame($exception->exception_number, $submission->exception_snapshot['exception_number']);
        $this->assertTrue($submission->exception_snapshot['is_excepted']);
    }

    public function test_resubmission_increments_revision_number_without_mutating_previous_revision(): void
    {
        $application = $this->makeApplication();
        $check = ShmCreditCheck::create([
            'shm_application_id' => $application->id,
            'filled_by' => $this->creditAnalyst->id,
            'status' => 'completed',
            'outcome' => 'pass',
            'result' => 'Aman',
            'started_at' => now()->subDay(),
            'checked_at' => now(),
        ]);

        $memorandum = ShmMemorandum::create([
            'shm_application_id' => $application->id,
            'created_by' => $this->creditAnalyst->id,
            'status' => ShmMemorandum::STATUS_DRAFT,
            'notes' => 'Draft naskah revisi awal.',
            'shm_credit_check_id' => $check->id,
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
        ]);

        $approvalService = app(ShmMemorandumApprovalService::class);

        // Submit 1 (Revision 1)
        $approvalService->submit($memorandum, $this->creditAnalyst);
        $sub1 = ShmMemorandumSubmission::where('shm_memorandum_id', $memorandum->id)->where('revision_number', 1)->first();
        $hash1 = $sub1->content_hash;

        // Transisi ke Under Review terlebih dahulu agar dapat di-reject
        $memorandum->refresh();
        $approvalService->startReview($memorandum, $this->direkturUtama);

        // Simulasi Reject oleh Pimpinan
        $approvalService->reject($memorandum, $this->direkturUtama, 'Harap perbaiki analisis repayment capacity');

        $this->assertSame(ShmMemorandum::STATUS_DRAFT, $memorandum->fresh()->status);
        $this->assertNotNull($memorandum->fresh()->rejected_at);

        // Edit naskah memorandum
        $memorandum->update([
            'notes' => 'Draft naskah yang sudah disempurnakan dengan kalkulasi baru.',
            'risk_mitigation_notes' => 'Tambahan analisis kompensasi.',
        ]);

        // Resubmit (Revision 2)
        $approvalService->submit($memorandum, $this->creditAnalyst);

        $sub2 = ShmMemorandumSubmission::where('shm_memorandum_id', $memorandum->id)->where('revision_number', 2)->first();
        $this->assertNotNull($sub2);
        $this->assertSame(2, $sub2->revision_number);
        $this->assertNotSame($hash1, $sub2->content_hash);

        // Pastikan Revision 1 tidak berubah sama sekali
        $sub1Fresh = ShmMemorandumSubmission::where('shm_memorandum_id', $memorandum->id)->where('revision_number', 1)->first();
        $this->assertSame($hash1, $sub1Fresh->content_hash);
        $this->assertSame('Draft naskah revisi awal.', $sub1Fresh->narrative_notes);
    }

    public function test_buyback_document_file_retention_when_referenced_in_submission(): void
    {
        Storage::fake('local');

        $application = $this->makeApplication('buyback');
        $check = ShmCreditCheck::create([
            'shm_application_id' => $application->id,
            'filled_by' => $this->creditAnalyst->id,
            'status' => 'completed',
            'outcome' => 'pass',
            'result' => 'Aman',
            'started_at' => now()->subDay(),
            'checked_at' => now(),
        ]);

        $memorandum = ShmMemorandum::create([
            'shm_application_id' => $application->id,
            'created_by' => $this->creditAnalyst->id,
            'status' => ShmMemorandum::STATUS_DRAFT,
            'notes' => 'Memorandum buyback',
            'shm_credit_check_id' => $check->id,
            'type' => ShmMemorandum::TYPE_BUYBACK,
        ]);

        // Lengkapi seluruh 5 dokumen buyback wajib
        $initialPath = 'buyback_docs/test_doc_v1.pdf';
        Storage::disk('local')->put($initialPath, 'Fake PDF Content V1');

        foreach (ShmBuybackDocument::REQUIRED_TYPES as $type) {
            $path = ($type === ShmBuybackDocument::TYPE_SHM) ? $initialPath : "buyback_docs/{$type}.pdf";
            ShmBuybackDocument::create([
                'shm_memorandum_id' => $memorandum->id,
                'document_type' => $type,
                'file_name' => "surat_{$type}.pdf",
                'file_path' => $path,
                'mime_type' => 'application/pdf',
                'file_size' => 1024,
                'uploaded_by' => $this->creditAnalyst->id,
            ]);
            if ($type !== ShmBuybackDocument::TYPE_SHM) {
                Storage::disk('local')->put("buyback_docs/{$type}.pdf", 'Fake PDF Content');
            }
        }

        // Submit memorandum -> file ini sekarang tercatat dalam submission revision 1
        $approvalService = app(ShmMemorandumApprovalService::class);
        $approvalService->submit($memorandum, $this->creditAnalyst);

        $submission = ShmMemorandumSubmission::where('shm_memorandum_id', $memorandum->id)->first();
        $this->assertNotNull($submission);
        $this->assertTrue(ShmMemorandumSubmission::isFilePathReferenced($initialPath));

        // Sekarang kita simulasikan penimpaan / penggantian dokumen
        $newPath = 'buyback_docs/test_doc_v2.pdf';
        Storage::disk('local')->put($newPath, 'Fake PDF Content V2');

        $doc = ShmBuybackDocument::where('shm_memorandum_id', $memorandum->id)
            ->where('document_type', ShmBuybackDocument::TYPE_SHM)
            ->first();

        $doc->update([
            'file_name' => 'surat_v2.pdf',
            'file_path' => $newPath,
        ]);

        // Logika penghapusan lama harus memeriksa isFilePathReferenced
        $oldFilePathToDelete = $initialPath;
        if ($oldFilePathToDelete && ! ShmMemorandumSubmission::isFilePathReferenced($oldFilePathToDelete) && Storage::disk('local')->exists($oldFilePathToDelete)) {
            Storage::disk('local')->delete($oldFilePathToDelete);
        }

        // Pastikan file lama tetap ada di disk storage karena merupakan bagian dari arsip revisi historis!
        $this->assertTrue(Storage::disk('local')->exists($initialPath));
        $this->assertTrue(Storage::disk('local')->exists($newPath));
    }
}
