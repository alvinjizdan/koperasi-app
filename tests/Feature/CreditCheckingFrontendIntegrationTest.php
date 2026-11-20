<?php

namespace Tests\Feature;

use App\Enums\ShmApplicationStatus;
use App\Enums\ShmCreditCheckOutcome;
use App\Enums\ShmCreditCheckStatus;
use App\Models\Role;
use App\Models\ShmApplication;
use App\Models\ShmBuybackDocument;
use App\Models\ShmCreditCheck;
use App\Models\ShmCreditCheckException;
use App\Models\ShmCustomer;
use App\Models\ShmMemorandum;
use App\Models\ShmMemorandumSubmission;
use App\Models\User;
use Database\Seeders\MakroPhysicalRoleSeeder;
use Database\Seeders\MakroSystemRoleSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CreditCheckingFrontendIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $otherMarketing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(MakroPhysicalRoleSeeder::class);
        $this->seed(MakroSystemRoleSeeder::class);

        $adminRole = Role::where('name', 'admin_makro')->first()
            ?? Role::where('name', 'superadmin')->first();

        $this->admin = User::factory()->create();
        if ($adminRole) {
            $this->admin->roles()->attach($adminRole->id);
        }

        $marketingRole = Role::where('name', 'marketing_makro')->first()
            ?? Role::where('name', 'marketing')->first();

        $this->otherMarketing = User::factory()->create();
        if ($marketingRole) {
            $this->otherMarketing->roles()->attach($marketingRole->id);
        }
    }

    private function makeApplication(string $type = 'shgb', ?User $owner = null): ShmApplication
    {
        $customer = ShmCustomer::factory()->create();
        $marketing = $owner ?? $this->admin;

        return ShmApplication::create([
            'application_number' => 'APP-TEST-'.uniqid(),
            'shm_customer_id' => $customer->id,
            'marketing_id' => $marketing->id,
            'type' => $type,
            'status' => ShmApplicationStatus::Completed,
        ]);
    }

    public function test_shm_shgb_show_page_receives_credit_check_and_submissions_props(): void
    {
        $application = $this->makeApplication(ShmApplication::TYPE_SHGB);

        $creditCheck = ShmCreditCheck::create([
            'shm_application_id' => $application->id,
            'status' => ShmCreditCheckStatus::Completed,
            'outcome' => ShmCreditCheckOutcome::Pass,
            'filled_by' => $this->admin->id,
            'checked_at' => now(),
            'result' => 'Sesuai standar verifikasi.',
        ]);

        $memorandum = ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'shm_application_id' => $application->id,
            'status' => ShmMemorandum::STATUS_DRAFT,
            'notes' => 'Draft catatan memorandum.',
            'created_by' => $this->admin->id,
        ]);

        // Simpan submission revision dummy
        $sub = ShmMemorandumSubmission::create([
            'shm_memorandum_id' => $memorandum->id,
            'revision_number' => 1,
            'memorandum_type' => ShmMemorandum::TYPE_SHM_SHGB,
            'submitted_by' => $this->admin->id,
            'submitted_by_name' => $this->admin->name,
            'submitted_at' => now(),
            'narrative_notes' => 'Catatan revisi 1',
            'borrower_snapshot' => ['name' => $application->customer->name, 'nik' => '3201123456780001'],
            'collateral_snapshot' => [],
            'credit_check_snapshot' => [
                'credit_check' => [
                    'outcome' => 'pass',
                    'outcome_label' => 'PASS',
                ],
            ],
            'template_version' => 'v1.0',
            'content_hash' => hash('sha256', 'test_data'),
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('shm.memorandums.shm-shgb.show', $memorandum->id));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Shm/Memorandum/ShmShgbShow')
            ->has('memorandum')
            ->has('credit_check')
            ->where('credit_check.outcome', 'pass')
            ->where('credit_check.status', 'completed')
            ->has('submissions', 1)
            ->where('submissions.0.revision_number', 1)
            ->where('submissions.0.content_hash', $sub->content_hash)
            ->where('submissions.0.borrower_snapshot.nik', '3201123456780001')
            ->has('active_exception')
            ->where('active_exception', null)
            ->has('can_override_fail')
            ->has('can_bypass_completion')
        );
    }

    public function test_buyback_show_page_receives_active_exception_without_internal_justification(): void
    {
        $application = $this->makeApplication(ShmApplication::TYPE_BUYBACK);

        // Dispensasi aktif dengan internal_justification rahasia
        $exception = ShmCreditCheckException::create([
            'exception_number' => 'EXC-202611-TEST01',
            'shm_application_id' => $application->id,
            'exception_type' => ShmCreditCheckException::TYPE_FAIL_OVERRIDE,
            'status' => ShmCreditCheckException::STATUS_AUTHORIZED,
            'authorized_by' => $this->admin->id,
            'authorized_by_name' => $this->admin->name,
            'authorized_at' => now(),
            'blocking_condition' => 'FAIL on Credit Check',
            'internal_justification' => 'RAHASIA INTERNAL: Hanya untuk direksi.',
            'official_disclosure_summary' => 'Dispensasi khusus atas persetujuan Direktur Utama.',
        ]);

        $memorandum = ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_BUYBACK,
            'shm_application_id' => $application->id,
            'status' => ShmMemorandum::STATUS_DRAFT,
            'notes' => 'Draft catatan buyback.',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('shm.memorandums.buyback.show', $memorandum->id));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Shm/Memorandum/BuybackShow')
            ->has('active_exception')
            ->where('active_exception.exception_number', 'EXC-202611-TEST01')
            ->where('active_exception.exception_type', 'fail_override')
            ->where('active_exception.official_disclosure_summary', 'Dispensasi khusus atas persetujuan Direktur Utama.')
            ->missing('active_exception.internal_justification') // Invariant CC-03A / CC-06
        );
    }

    public function test_unauthorized_user_cannot_access_memorandum_or_its_sensitive_snapshots(): void
    {
        // Pengajuan milik admin
        $application = $this->makeApplication(ShmApplication::TYPE_SHGB, $this->admin);

        $memorandum = ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'shm_application_id' => $application->id,
            'status' => ShmMemorandum::STATUS_DRAFT,
            'created_by' => $this->admin->id,
        ]);

        // User marketing lain (tanpa shm.view_all) mencoba mengakses
        $response = $this->actingAs($this->otherMarketing)
            ->get(route('shm.memorandums.shm-shgb.show', $memorandum->id));

        $response->assertStatus(403);
    }

    public function test_consumed_or_revoked_exceptions_are_not_delivered_as_active_exception(): void
    {
        $application = $this->makeApplication(ShmApplication::TYPE_SHGB);

        $memorandum = ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'shm_application_id' => $application->id,
            'status' => ShmMemorandum::STATUS_DRAFT,
            'created_by' => $this->admin->id,
        ]);

        // Buat satu exception yang sudah status 'consumed'
        ShmCreditCheckException::create([
            'exception_number' => 'EXC-CONSUMED-01',
            'shm_application_id' => $application->id,
            'exception_type' => ShmCreditCheckException::TYPE_FAIL_OVERRIDE,
            'status' => ShmCreditCheckException::STATUS_CONSUMED,
            'authorized_by' => $this->admin->id,
            'authorized_by_name' => $this->admin->name,
            'authorized_at' => now()->subDays(2),
            'consumed_memorandum_id' => $memorandum->id,
            'consumed_at' => now()->subDay(),
            'blocking_condition' => 'FAIL',
            'internal_justification' => 'Sudah terpakai',
            'official_disclosure_summary' => 'Sudah terpakai',
        ]);

        // Buat satu exception yang status 'revoked'
        ShmCreditCheckException::create([
            'exception_number' => 'EXC-REVOKED-01',
            'shm_application_id' => $application->id,
            'exception_type' => ShmCreditCheckException::TYPE_COMPLETION_BYPASS,
            'status' => ShmCreditCheckException::STATUS_REVOKED,
            'authorized_by' => $this->admin->id,
            'authorized_by_name' => $this->admin->name,
            'authorized_at' => now()->subDay(),
            'revoked_by' => $this->admin->id,
            'revoked_at' => now(),
            'revocation_reason' => 'Dibatalkan oleh Direksi',
            'blocking_condition' => 'In Progress',
            'internal_justification' => 'Dibatalkan',
            'official_disclosure_summary' => 'Dibatalkan',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('shm.memorandums.shm-shgb.show', $memorandum->id));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Shm/Memorandum/ShmShgbShow')
            ->where('active_exception', null)
        );
    }

    public function test_historical_revisions_preserve_independent_snapshots_across_revisions(): void
    {
        $application = $this->makeApplication(ShmApplication::TYPE_SHGB);

        $memorandum = ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'shm_application_id' => $application->id,
            'status' => ShmMemorandum::STATUS_DRAFT,
            'notes' => 'Draft catatan saat ini yang sudah diedit berkali-kali.',
            'created_by' => $this->admin->id,
        ]);

        // Revision #1 snapshot (Catatan awal)
        $sub1 = ShmMemorandumSubmission::create([
            'shm_memorandum_id' => $memorandum->id,
            'revision_number' => 1,
            'memorandum_type' => ShmMemorandum::TYPE_SHM_SHGB,
            'submitted_by' => $this->admin->id,
            'submitted_by_name' => $this->admin->name,
            'submitted_at' => now()->subDays(3),
            'narrative_notes' => 'Teks Revisi 1 Asli',
            'risk_mitigation_notes' => 'Mitigasi Revisi 1',
            'borrower_snapshot' => ['name' => 'Nama Nasabah Awal', 'nik' => '1111'],
            'collateral_snapshot' => [['certificate_number' => 'SHM-001']],
            'credit_check_snapshot' => ['credit_check' => ['outcome' => 'review']],
            'template_version' => 'v1.0',
            'content_hash' => hash('sha256', 'hash_rev_1'),
        ]);

        // Revision #2 snapshot (Setelah perbaikan)
        $sub2 = ShmMemorandumSubmission::create([
            'shm_memorandum_id' => $memorandum->id,
            'revision_number' => 2,
            'memorandum_type' => ShmMemorandum::TYPE_SHM_SHGB,
            'submitted_by' => $this->admin->id,
            'submitted_by_name' => $this->admin->name,
            'submitted_at' => now()->subDay(),
            'narrative_notes' => 'Teks Revisi 2 Diperbarui',
            'risk_mitigation_notes' => 'Mitigasi Revisi 2 Lebih Lengkap',
            'borrower_snapshot' => ['name' => 'Nama Nasabah Awal', 'nik' => '1111'],
            'collateral_snapshot' => [['certificate_number' => 'SHM-001'], ['certificate_number' => 'SHM-002']],
            'credit_check_snapshot' => ['credit_check' => ['outcome' => 'review']],
            'template_version' => 'v1.0',
            'content_hash' => hash('sha256', 'hash_rev_2'),
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('shm.memorandums.shm-shgb.show', $memorandum->id));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Shm/Memorandum/ShmShgbShow')
            ->has('submissions', 2)
            // Revisi 2 adalah yang terbaru (index 0)
            ->where('submissions.0.revision_number', 2)
            ->where('submissions.0.narrative_notes', 'Teks Revisi 2 Diperbarui')
            ->where('submissions.0.content_hash', $sub2->content_hash)
            ->where('submissions.0.collateral_snapshot.1.certificate_number', 'SHM-002')
            // Revisi 1 tetap utuh dan independen (index 1)
            ->where('submissions.1.revision_number', 1)
            ->where('submissions.1.narrative_notes', 'Teks Revisi 1 Asli')
            ->where('submissions.1.content_hash', $sub1->content_hash)
            ->has('submissions.1.collateral_snapshot', 1)
        );
    }

    public function test_historical_buyback_document_download_authorization_and_safety(): void
    {
        Storage::fake('local');

        $application = $this->makeApplication(ShmApplication::TYPE_BUYBACK);

        $memorandum = ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_BUYBACK,
            'shm_application_id' => $application->id,
            'status' => ShmMemorandum::STATUS_DRAFT,
            'created_by' => $this->admin->id,
        ]);

        $pdfContent = '%PDF-1.4 Fake PDF Content for Buyback Document';
        $storedPath = 'shm_buyback_documents/test_legal_doc.pdf';
        Storage::disk('local')->put($storedPath, $pdfContent);

        $doc = ShmBuybackDocument::create([
            'shm_memorandum_id' => $memorandum->id,
            'shm_application_id' => $application->id,
            'document_type' => 'akta_buyback',
            'file_name' => 'akta_buyback_original.pdf',
            'file_path' => $storedPath,
            'file_size' => strlen($pdfContent),
            'mime_type' => 'application/pdf',
            'uploaded_by' => $this->admin->id,
        ]);

        // 1. Authorized download works
        $downloadUrl = route('shm.memorandums.buyback.documents.download', [
            'memorandum' => $memorandum->id,
            'document' => $doc->id,
        ]);

        $res = $this->actingAs($this->admin)->get($downloadUrl);
        $res->assertOk();
        $res->assertHeader('Content-Type', 'application/pdf');
        $res->assertHeader('Content-Disposition', 'inline; filename="akta_buyback_original.pdf"');
        $this->assertEquals($pdfContent, $res->getContent());

        // 2. Unauthorized user cannot download through direct URL
        $unauthRes = $this->actingAs($this->otherMarketing)->get($downloadUrl);
        $unauthRes->assertStatus(403);

        // 3. Missing file produces 404 controlled response without path disclosure
        Storage::disk('local')->delete($storedPath);
        $missingRes = $this->actingAs($this->admin)->get($downloadUrl);
        $missingRes->assertStatus(404);
        $this->assertStringNotContainsString('storage/app', $missingRes->getContent() ?: '');
    }
}
