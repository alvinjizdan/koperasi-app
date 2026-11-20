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
use Database\Seeders\MakroPhysicalRoleSeeder;
use Database\Seeders\MakroSystemRoleSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CreditCheckingWorkflowIntegrityHardeningTest extends TestCase
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

    public function test_exception_consumption_rolls_back_if_submission_fails(): void
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
        $exception = $exceptionService->authorizeException(
            $application,
            ShmCreditCheckException::TYPE_FAIL_OVERRIDE,
            $this->direkturUtama,
            'Justifikasi mitigasi risiko kuat atas persetujuan Direktur Utama.',
            'Ringkasan resmi disclosure Direktur Utama.',
            'Hasil credit check FAIL'
        );

        $memorandum = ShmMemorandum::create([
            'shm_application_id' => $application->id,
            'created_by' => $this->creditAnalyst->id,
            'status' => ShmMemorandum::STATUS_DRAFT,
            'notes' => 'Catatan telaah memorandum.',
            'shm_credit_check_id' => $check->id,
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
        ]);

        // Simulasikan kegagalan transaksi di tengah-tengah submit() dengan listener/mock atau trigger exception
        try {
            DB::transaction(function () use ($exceptionService, $application, $memorandum) {
                $exceptionService->consumeException($application, ShmCreditCheckException::TYPE_FAIL_OVERRIDE, $memorandum);
                throw new \RuntimeException('Simulasi kegagalan penyimpanan submission artifact.');
            });
            $this->fail('Harusnya melempar RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulasi kegagalan penyimpanan submission artifact.', $e->getMessage());
        }

        // Verifikasi bahwa status exception tetap AUTHORIZED (rollback penuh)
        $exception->refresh();
        $this->assertSame(ShmCreditCheckException::STATUS_AUTHORIZED, $exception->status);
        $this->assertNull($exception->consumed_at);
        $this->assertNull($exception->consumed_memorandum_id);
    }

    public function test_cannot_consume_revoked_or_expired_exception(): void
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
        $exception = $exceptionService->authorizeException(
            $application,
            ShmCreditCheckException::TYPE_FAIL_OVERRIDE,
            $this->direkturUtama,
            'Justifikasi mitigasi risiko kuat atas persetujuan Direktur Utama.',
            'Ringkasan resmi disclosure Direktur Utama.',
            'Hasil credit check FAIL'
        );

        // Cabut dispensasi
        $exceptionService->revokeException($exception, $this->direkturUtama, 'Informasi agunan fiktif dari debitur.');
        $this->assertSame(ShmCreditCheckException::STATUS_REVOKED, $exception->fresh()->status);

        $memorandum = ShmMemorandum::create([
            'shm_application_id' => $application->id,
            'created_by' => $this->creditAnalyst->id,
            'status' => ShmMemorandum::STATUS_DRAFT,
            'notes' => 'Catatan telaah memorandum.',
            'shm_credit_check_id' => $check->id,
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('fail_override');

        $exceptionService->consumeException($application, ShmCreditCheckException::TYPE_FAIL_OVERRIDE, $memorandum);
    }

    public function test_exception_application_scope_mismatch_is_rejected(): void
    {
        $app1 = $this->makeApplication();
        $app2 = $this->makeApplication();

        $check = ShmCreditCheck::create([
            'shm_application_id' => $app1->id,
            'filled_by' => $this->creditAnalyst->id,
            'status' => 'completed',
            'outcome' => 'fail',
            'result' => 'Riwayat SLIK DPK 5 macet total',
            'started_at' => now()->subDay(),
            'checked_at' => now(),
        ]);

        $exceptionService = app(ShmCreditCheckExceptionService::class);
        $exceptionService->authorizeException(
            $app1,
            ShmCreditCheckException::TYPE_FAIL_OVERRIDE,
            $this->direkturUtama,
            'Justifikasi mitigasi risiko kuat atas persetujuan Direktur Utama.',
            'Ringkasan resmi disclosure Direktur Utama.',
            'Hasil credit check FAIL'
        );

        $memo2 = ShmMemorandum::create([
            'shm_application_id' => $app2->id,
            'created_by' => $this->creditAnalyst->id,
            'status' => ShmMemorandum::STATUS_DRAFT,
            'notes' => 'Mencoba mengonsumsi dispensasi milik aplikasi 1.',
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
        ]);

        // Coba konsumsi dispensasi untuk aplikasi 2 (padahal dispensasi terdaftar di aplikasi 1)
        $this->expectException(ValidationException::class);

        $exceptionService->consumeException($app2, ShmCreditCheckException::TYPE_FAIL_OVERRIDE, $memo2);
    }

    public function test_consumed_exception_cannot_be_reused_on_subsequent_revision(): void
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
        $exception = $exceptionService->authorizeException(
            $application,
            ShmCreditCheckException::TYPE_FAIL_OVERRIDE,
            $this->direkturUtama,
            'Justifikasi mitigasi risiko kuat atas persetujuan Direktur Utama.',
            'Ringkasan resmi disclosure Direktur Utama.',
            'Hasil credit check FAIL'
        );

        $memorandum = ShmMemorandum::create([
            'shm_application_id' => $application->id,
            'created_by' => $this->creditAnalyst->id,
            'status' => ShmMemorandum::STATUS_DRAFT,
            'notes' => 'Pengajuan override revisi 1.',
            'shm_credit_check_id' => $check->id,
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
        ]);

        $approvalService = app(ShmMemorandumApprovalService::class);

        // Submit Revision 1 -> Dispensasi berhasil dikonsumsi
        $approvalService->submit($memorandum, $this->creditAnalyst);
        $this->assertSame(ShmCreditCheckException::STATUS_CONSUMED, $exception->fresh()->status);

        // Pimpinan mereview dan menolak memorandum
        $memorandum->refresh();
        $approvalService->startReview($memorandum, $this->direkturUtama);
        $approvalService->reject($memorandum, $this->direkturUtama, 'Perbaiki jaminan tambahan');

        // Saat analis kredit mencoba resubmit (Revisi 2), dispensasi FAIL Override sudah consumed dan tidak boleh dikonsumsi lagi tanpa persetujuan baru!
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('FAIL');

        $approvalService->submit($memorandum->fresh(), $this->creditAnalyst);
    }

    public function test_destroy_document_does_not_delete_physical_file_if_referenced_in_submission(): void
    {
        Storage::fake('local');

        $application = $this->makeApplication(ShmApplication::TYPE_BUYBACK);
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

        $initialPath = 'buyback_docs/akta_buyback_v1.pdf';
        Storage::disk('local')->put($initialPath, 'Fake Akta PDF Content');

        // Buat 5 dokumen wajib buyback
        foreach (ShmBuybackDocument::REQUIRED_TYPES as $type) {
            $path = ($type === ShmBuybackDocument::TYPE_AKTA_BUYBACK) ? $initialPath : "buyback_docs/{$type}.pdf";
            ShmBuybackDocument::create([
                'shm_memorandum_id' => $memorandum->id,
                'document_type' => $type,
                'file_name' => "surat_{$type}.pdf",
                'file_path' => $path,
                'mime_type' => 'application/pdf',
                'file_size' => 1024,
                'uploaded_by' => $this->creditAnalyst->id,
            ]);
            if ($type !== ShmBuybackDocument::TYPE_AKTA_BUYBACK) {
                Storage::disk('local')->put("buyback_docs/{$type}.pdf", 'Fake PDF Content');
            }
        }

        // Submit memorandum -> file dicatat dalam submission revision 1
        $approvalService = app(ShmMemorandumApprovalService::class);
        $approvalService->submit($memorandum, $this->creditAnalyst);

        $this->assertTrue(ShmMemorandumSubmission::isFilePathReferenced($initialPath));

        // Hapus record dokumen dari database (misalnya admin menghapus dokumen akta)
        $doc = ShmBuybackDocument::where('shm_memorandum_id', $memorandum->id)
            ->where('document_type', ShmBuybackDocument::TYPE_AKTA_BUYBACK)
            ->first();

        $filePath = $doc->file_path;
        $doc->delete();

        // Logika penghapusan file Controller
        if ($filePath && ! ShmMemorandumSubmission::isFilePathReferenced($filePath) && Storage::disk('local')->exists($filePath)) {
            Storage::disk('local')->delete($filePath);
        }

        // File fisik di disk storage WAJIB tetap ada demi integritas arsip submission revision!
        $this->assertTrue(Storage::disk('local')->exists($initialPath));
    }
}
