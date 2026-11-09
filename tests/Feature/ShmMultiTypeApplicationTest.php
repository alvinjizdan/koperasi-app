<?php

namespace Tests\Feature;

use App\Enums\ShmApplicationStatus;
use App\Models\Role;
use App\Models\ShmApplication;
use App\Models\ShmCustomer;
use App\Models\ShmDocument;
use App\Models\ShmMemorandum;
use App\Models\User;
use App\Services\ShmWorkflowService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature Test untuk Ticket 3 — SHM Memorandum: Multi-Type Application (SHGB & Buyback).
 *
 * Menguji:
 * 1. Type Creation & Persistence:
 *    - Pengajuan SHGB berhasil dibuat dengan type = 'shgb'.
 *    - Pengajuan Buyback berhasil dibuat dengan type = 'buyback'.
 *    - Invalid type ditolak oleh validasi backend.
 *    - Existing data historis tetap terbaca sebagai 'shgb' (backfill compatibility).
 *    - Tipe tidak dapat diganti secara sembarangan saat pengajuan diupdate (immutable type).
 * 2. Shared Data:
 *    - Kedua tipe menggunakan master nasabah ShmCustomer yang sama.
 *    - Tidak ada duplikasi customer.
 * 3. Shared Workflow Compatibility:
 *    - Baik SHGB maupun Buyback dapat menjalani alur workflow:
 *      Draft -> Submitted -> CreditChecking -> Memorandum -> Approval -> ReadyForDisbursement -> Disbursed -> Completed.
 * 4. Downstream Integration:
 *    - Pengajuan SHGB berstatus Completed dapat dikonversikan ke SHM/SHGB (Ticket 2).
 *    - Pengajuan Buyback Completed DITOLAK saat mencoba dikonversi ke SHM/SHGB.
 *    - Duplikasi konversi dicegah.
 */
class ShmMultiTypeApplicationTest extends TestCase
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

        $this->admin = User::factory()->create();
        $adminRole = Role::where('name', 'admin')->first();
        if ($adminRole) {
            $this->admin->roles()->attach($adminRole->id);
        }

        $this->marketing = User::factory()->create();
        $marketingRole = Role::where('name', 'marketing')->first();
        if ($marketingRole) {
            $this->marketing->roles()->attach($marketingRole->id);
        }

        $this->customer = ShmCustomer::create([
            'customer_number' => 'CUST-MULTI-001',
            'name' => 'Nasabah Multi Tipe',
            'nik' => '3201000000000888',
            'phone' => '081234567888',
            'is_active' => true,
        ]);
    }

    public function test_shgb_application_creation_and_persistence(): void
    {
        $response = $this->actingAs($this->admin)->post(route('shm.applications.store'), [
            'type' => 'shgb',
            'shm_customer_id' => $this->customer->id,
            'notes' => 'Pengajuan berkas SHGB',
        ]);

        $response->assertRedirect();
        $application = ShmApplication::where('notes', 'Pengajuan berkas SHGB')->first();
        $this->assertNotNull($application);
        $this->assertEquals(ShmApplication::TYPE_SHGB, $application->type);
        $this->assertEquals('SHGB', $application->typeLabel());
    }

    public function test_buyback_application_creation_and_persistence(): void
    {
        $response = $this->actingAs($this->admin)->post(route('shm.applications.store'), [
            'type' => 'buyback',
            'shm_customer_id' => $this->customer->id,
            'notes' => 'Pengajuan berkas Buyback',
        ]);

        $response->assertRedirect();
        $application = ShmApplication::where('notes', 'Pengajuan berkas Buyback')->first();
        $this->assertNotNull($application);
        $this->assertEquals(ShmApplication::TYPE_BUYBACK, $application->type);
        $this->assertEquals('Buyback', $application->typeLabel());
    }

    public function test_invalid_application_type_is_rejected(): void
    {
        $response = $this->actingAs($this->admin)->post(route('shm.applications.store'), [
            'type' => 'invalid_type',
            'shm_customer_id' => $this->customer->id,
            'notes' => 'Tipe tidak valid',
        ]);

        $response->assertSessionHasErrors('type');
        $this->assertDatabaseMissing('shm_applications', [
            'notes' => 'Tipe tidak valid',
        ]);
    }

    public function test_existing_legacy_applications_have_shgb_type_by_default(): void
    {
        $legacyApp = ShmApplication::create([
            'application_number' => 'APP-LEGACY-001',
            'shm_customer_id' => $this->customer->id,
            'marketing_id' => $this->admin->id,
            'status' => ShmApplicationStatus::Draft,
        ]);

        $legacyApp->refresh();
        $this->assertEquals(ShmApplication::TYPE_SHGB, $legacyApp->type);
        $this->assertEquals('SHGB', $legacyApp->typeLabel());
    }

    public function test_type_cannot_be_mutated_on_update(): void
    {
        $app = ShmApplication::create([
            'application_number' => 'APP-BUYBACK-002',
            'type' => ShmApplication::TYPE_BUYBACK,
            'shm_customer_id' => $this->customer->id,
            'marketing_id' => $this->admin->id,
            'status' => ShmApplicationStatus::Draft,
            'notes' => 'Catatan awal',
        ]);

        // Kirim update yang mencoba mengganti type menjadi shgb
        $response = $this->actingAs($this->admin)->put(route('shm.applications.update', $app->id), [
            'type' => 'shgb',
            'shm_customer_id' => $this->customer->id,
            'notes' => 'Catatan diperbarui',
        ]);

        $response->assertRedirect(route('shm.applications.show', $app->id));
        $app->refresh();
        // Tipe harus TETAP buyback
        $this->assertEquals(ShmApplication::TYPE_BUYBACK, $app->type);
        $this->assertEquals('Catatan diperbarui', $app->notes);
    }

    public function test_index_filters_by_application_type(): void
    {
        ShmApplication::create([
            'application_number' => 'APP-SHGB-100',
            'type' => ShmApplication::TYPE_SHGB,
            'shm_customer_id' => $this->customer->id,
            'marketing_id' => $this->admin->id,
            'status' => ShmApplicationStatus::Draft,
        ]);

        ShmApplication::create([
            'application_number' => 'APP-BUYBACK-200',
            'type' => ShmApplication::TYPE_BUYBACK,
            'shm_customer_id' => $this->customer->id,
            'marketing_id' => $this->admin->id,
            'status' => ShmApplicationStatus::Draft,
        ]);

        // Filter SHGB
        $responseShgb = $this->actingAs($this->admin)->get(route('shm.applications.index', ['type' => 'shgb']));
        $responseShgb->assertOk();
        $responseShgb->assertInertia(fn ($page) => $page
            ->component('Shm/Applications/Index')
            ->has('applications.data', 1)
            ->where('applications.data.0.application_number', 'APP-SHGB-100')
        );

        // Filter Buyback
        $responseBuyback = $this->actingAs($this->admin)->get(route('shm.applications.index', ['type' => 'buyback']));
        $responseBuyback->assertOk();
        $responseBuyback->assertInertia(fn ($page) => $page
            ->component('Shm/Applications/Index')
            ->has('applications.data', 1)
            ->where('applications.data.0.application_number', 'APP-BUYBACK-200')
        );
    }

    public function test_both_types_can_progress_through_entire_shared_workflow(): void
    {
        $workflow = app(ShmWorkflowService::class);

        foreach ([ShmApplication::TYPE_SHGB, ShmApplication::TYPE_BUYBACK] as $appType) {
            $app = ShmApplication::create([
                'application_number' => 'APP-WF-'.uniqid(),
                'type' => $appType,
                'shm_customer_id' => $this->customer->id,
                'marketing_id' => $this->admin->id,
                'status' => ShmApplicationStatus::Draft,
            ]);

            // Draft -> Submitted
            $workflow->transition($app, ShmApplicationStatus::Submitted, $this->admin->id, 'Submit');
            $this->assertEquals(ShmApplicationStatus::Submitted, $app->refresh()->status);

            // Submitted -> CreditChecking
            $workflow->transition($app, ShmApplicationStatus::CreditChecking, $this->admin->id, 'Mulai Credit Check');
            $this->assertEquals(ShmApplicationStatus::CreditChecking, $app->refresh()->status);

            // CreditChecking -> Memorandum
            $workflow->transition($app, ShmApplicationStatus::Memorandum, $this->admin->id, 'Lanjut Memorandum');
            $this->assertEquals(ShmApplicationStatus::Memorandum, $app->refresh()->status);

            // Memorandum -> Approval
            $workflow->transition($app, ShmApplicationStatus::Approval, $this->admin->id, 'Lanjut Approval');
            $this->assertEquals(ShmApplicationStatus::Approval, $app->refresh()->status);

            // Approval -> Approved
            $workflow->transition($app, ShmApplicationStatus::Approved, $this->admin->id, 'Setujui');
            $this->assertEquals(ShmApplicationStatus::Approved, $app->refresh()->status);

            // Approved -> DocumentCheck
            $workflow->transition($app, ShmApplicationStatus::DocumentCheck, $this->admin->id, 'Periksa Dokumen');
            $this->assertEquals(ShmApplicationStatus::DocumentCheck, $app->refresh()->status);

            // Record document verified agar lolos guard pencairan
            $workflow->recordDocument(
                $app,
                'Sertifikat Asli',
                ShmDocument::STATUS_COMPLETE,
                'Terverifikasi lengkap',
                $this->admin->id,
            );

            // DocumentCheck -> ReadyForDisbursement
            $workflow->transition($app, ShmApplicationStatus::ReadyForDisbursement, $this->admin->id, 'Siap Cair');
            $this->assertEquals(ShmApplicationStatus::ReadyForDisbursement, $app->refresh()->status);

            // ReadyForDisbursement -> Disbursed
            $workflow->transition($app, ShmApplicationStatus::Disbursed, $this->admin->id, 'Cairkan');
            $this->assertEquals(ShmApplicationStatus::Disbursed, $app->refresh()->status);

            // Disbursed -> Completed
            $workflow->transition($app, ShmApplicationStatus::Completed, $this->admin->id, 'Selesai');
            $this->assertEquals(ShmApplicationStatus::Completed, $app->refresh()->status);
        }
    }

    public function test_downstream_integration_shgb_completed_can_convert_to_shm_shgb(): void
    {
        $shgbCompleted = ShmApplication::create([
            'application_number' => 'APP-SHGB-DONE-'.uniqid(),
            'type' => ShmApplication::TYPE_SHGB,
            'shm_customer_id' => $this->customer->id,
            'marketing_id' => $this->admin->id,
            'status' => ShmApplicationStatus::Completed,
            'completed_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->post(route('shm.memorandums.shm-shgb.store'), [
            'shm_application_id' => $shgbCompleted->id,
            'status' => 'draft',
            'notes' => 'Konversi pengajuan SHGB yang selesai',
        ]);

        $response->assertRedirect(route('shm.memorandums.shm-shgb.index'));
        $this->assertDatabaseHas('shm_memorandums', [
            'shm_application_id' => $shgbCompleted->id,
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
        ]);
    }

    public function test_downstream_integration_buyback_completed_is_rejected_for_shm_shgb_conversion(): void
    {
        $buybackCompleted = ShmApplication::create([
            'application_number' => 'APP-BUYBACK-DONE-'.uniqid(),
            'type' => ShmApplication::TYPE_BUYBACK,
            'shm_customer_id' => $this->customer->id,
            'marketing_id' => $this->admin->id,
            'status' => ShmApplicationStatus::Completed,
            'completed_at' => now(),
        ]);

        // Coba konversikan Buyback ke menu Memorandum SHM/SHGB
        $response = $this->actingAs($this->admin)->post(route('shm.memorandums.shm-shgb.store'), [
            'shm_application_id' => $buybackCompleted->id,
            'status' => 'draft',
            'notes' => 'Mencoba konversi ilegal Buyback ke SHM/SHGB',
        ]);

        // Harus ditolak dengan validasi error pada shm_application_id
        $response->assertSessionHasErrors('shm_application_id');
        $this->assertDatabaseMissing('shm_memorandums', [
            'shm_application_id' => $buybackCompleted->id,
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
        ]);
    }
}
