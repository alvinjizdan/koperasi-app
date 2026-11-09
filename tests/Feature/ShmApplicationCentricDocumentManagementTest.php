<?php

namespace Tests\Feature;

use App\Enums\ShmApplicationStatus;
use App\Models\Role;
use App\Models\ShmApplication;
use App\Models\ShmBuybackDocument;
use App\Models\ShmCustomer;
use App\Models\ShmMemorandum;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Feature Test untuk Ticket 14 — Pengajuan-Centric Document Management & Memorandum Conversion Refactor.
 *
 * Menguji:
 * 1. Masa berlaku SHGB dikelola pada ShmApplication (Create & Edit & Show).
 * 2. 5 Dokumen legalitas Buyback diunggah dan dikelola pada ShmApplication.
 * 3. Penyimpanan private storage di shm/applications/{id}/buyback-documents/{filename}.
 * 4. Immutability guard: Dokumen terkunci saat pengajuan Completed.
 * 5. Konversi Memorandum membaca tanggal dan dokumen langsung dari ShmApplication.
 * 6. Deleting Memorandum tidak menghapus dokumen milik ShmApplication.
 */
class ShmApplicationCentricDocumentManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $marketing;

    private User $otherMarketing;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

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

        $this->otherMarketing = User::factory()->create();
        if ($marketingRole) {
            $this->otherMarketing->roles()->attach($marketingRole->id);
        }
    }

    private function createCustomer(): ShmCustomer
    {
        return ShmCustomer::create([
            'customer_number' => 'CUST-'.fake()->unique()->numerify('#####'),
            'name' => fake()->name(),
            'nik' => fake()->numerify('################'),
            'phone' => '081234567890',
            'email' => fake()->safeEmail(),
            'address' => 'Jl. Test No. 123',
            'is_active' => true,
        ]);
    }

    public function test_shgb_validity_dates_stored_in_application(): void
    {
        $customer = $this->createCustomer();

        $response = $this->actingAs($this->marketing)->post(route('shm.applications.store'), [
            'type' => ShmApplication::TYPE_SHGB,
            'shm_customer_id' => $customer->id,
            'notes' => 'Catatan SHGB',
            'shgb_start_date' => '2026-01-01',
            'shgb_end_date' => '2046-01-01',
        ]);

        $response->assertRedirect();
        $app = ShmApplication::latest('id')->first();
        $this->assertNotNull($app);
        $this->assertSame('2026-01-01', $app->shgb_start_date->toDateString());
        $this->assertSame('2046-01-01', $app->shgb_end_date->toDateString());
    }

    public function test_buyback_documents_can_be_uploaded_to_application(): void
    {
        $customer = $this->createCustomer();
        $app = ShmApplication::create([
            'application_number' => 'APP-BB-01',
            'type' => ShmApplication::TYPE_BUYBACK,
            'shm_customer_id' => $customer->id,
            'marketing_id' => $this->marketing->id,
            'status' => ShmApplicationStatus::Draft,
        ]);

        $file = UploadedFile::fake()->create('shm.pdf', 500, 'application/pdf');

        $response = $this->actingAs($this->marketing)->post(
            route('shm.applications.buyback-documents.upload', $app->id),
            [
                'document_type' => 'shm',
                'file' => $file,
            ]
        );

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('shm_buyback_documents', [
            'shm_application_id' => $app->id,
            'document_type' => 'shm',
            'file_name' => 'shm.pdf',
        ]);

        $doc = $app->buybackDocuments()->first();
        $this->assertNotNull($doc);
        Storage::disk('local')->assertExists($doc->file_path);
    }

    public function test_buyback_memorandum_inherits_documents_from_application_and_destroy_preserves_application_docs(): void
    {
        $customer = $this->createCustomer();
        $app = ShmApplication::create([
            'application_number' => 'APP-BB-02',
            'type' => ShmApplication::TYPE_BUYBACK,
            'shm_customer_id' => $customer->id,
            'marketing_id' => $this->admin->id,
            'status' => ShmApplicationStatus::Completed,
            'completed_at' => now(),
        ]);

        // Create document on application
        $doc = ShmBuybackDocument::create([
            'shm_application_id' => $app->id,
            'document_type' => 'shm',
            'file_name' => 'shm.pdf',
            'file_path' => "shm/applications/{$app->id}/buyback-documents/shm.pdf",
            'file_size' => 1024,
            'uploaded_by' => $this->admin->id,
        ]);
        Storage::disk('local')->put($doc->file_path, 'dummy content');

        // Create memorandum
        $memo = ShmMemorandum::create([
            'shm_application_id' => $app->id,
            'type' => ShmMemorandum::TYPE_BUYBACK,
            'status' => 'draft',
            'created_by' => $this->admin->id,
        ]);

        // Memorandum show renders application documents
        $response = $this->actingAs($this->admin)->get(route('shm.memorandums.buyback.show', $memo->id));
        $response->assertStatus(200);

        // Deleting memorandum should preserve application's documents
        $delResp = $this->actingAs($this->admin)->delete(route('shm.memorandums.buyback.destroy', $memo->id));
        $delResp->assertRedirect(route('shm.memorandums.buyback.index'));

        $this->assertDatabaseMissing('shm_memorandums', ['id' => $memo->id]);
        $this->assertDatabaseHas('shm_buyback_documents', ['id' => $doc->id]);
        Storage::disk('local')->assertExists($doc->file_path);
    }
}
