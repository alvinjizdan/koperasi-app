<?php

namespace Tests\Feature;

use App\Enums\ShmApplicationStatus;
use App\Models\Role;
use App\Models\ShmApplication;
use App\Models\ShmCustomer;
use App\Models\ShmMemorandum;
use App\Models\ShmProperty;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature Test untuk Ticket 4 — SHM Memorandum: Buyback Data Management & Application Integration.
 *
 * Menguji:
 * 1. Eligibility Check:
 *    - Hanya pengajuan bertipe 'buyback' dengan status Completed yang dapat dikonversi menjadi data Buyback.
 *    - Status Draft, Submitted, CreditChecking, Memorandum, Approval, Approved, Ready, Disbursed, Rejected, Cancelled DITOLAK.
 *    - Pengajuan tipe SHGB (walau berstatus Completed) DITOLAK.
 *    - Pengajuan Buyback yang sudah pernah dikonversi DITOLAK (proteksi duplikasi 1:1).
 * 2. Full CRUD:
 *    - Index: Hanya menampilkan record bertipe 'buyback'. Data SHM/SHGB tidak muncul.
 *    - Create / Picker: Hanya menampilkan pengajuan Buyback Completed yang belum dikonversi.
 *    - Show: Menampilkan data memorandum dan konteks pengajuan asal (nasabah, properti/aset).
 *    - Edit & Update: Memperbarui status dan catatan tanpa mengubah pengajuan asal atau tipenya.
 *    - Destroy: Menghapus record ShmMemorandum secara aman tanpa merusak ShmApplication, ShmCustomer, atau ShmProperty.
 * 3. Security & Authorization:
 *    - Akses unauthorized ditolak (403).
 *    - IDOR protection: Marketing tanpa shm.view_all tidak dapat mengakses memorandum milik pihak lain.
 */
class ShmMemorandumBuybackManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $marketing;

    private User $otherMarketing;

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

        $this->otherMarketing = User::factory()->create();
        if ($marketingRole) {
            $this->otherMarketing->roles()->attach($marketingRole->id);
        }

        $this->customer = ShmCustomer::create([
            'customer_number' => 'CUST-BB-001',
            'name' => 'Nasabah Buyback Test',
            'nik' => '3201000000000777',
            'phone' => '081234567777',
            'is_active' => true,
        ]);
    }

    private function createShmApplication(
        string $type,
        ShmApplicationStatus $status,
        ?User $marketingUser = null
    ): ShmApplication {
        $app = ShmApplication::create([
            'application_number' => 'APP-'.strtoupper($type).'-'.uniqid(),
            'type' => $type,
            'shm_customer_id' => $this->customer->id,
            'marketing_id' => ($marketingUser ?? $this->marketing)->id,
            'status' => $status,
            'completed_at' => $status === ShmApplicationStatus::Completed ? now() : null,
        ]);

        ShmProperty::create([
            'shm_application_id' => $app->id,
            'certificate_type' => 'SHM',
            'certificate_number' => 'SHM-BB-'.uniqid(),
            'owner_name' => $this->customer->name,
            'address' => 'Jl. Buyback Sejahtera No. 10',
        ]);

        return $app;
    }

    public function test_only_completed_buyback_application_is_eligible_for_buyback_creation(): void
    {
        $completedBuyback = $this->createShmApplication(ShmApplication::TYPE_BUYBACK, ShmApplicationStatus::Completed);

        $response = $this->actingAs($this->admin)->post(route('shm.memorandums.buyback.store'), [
            'shm_application_id' => $completedBuyback->id,
            'status' => 'draft',
            'notes' => 'Catatan kelayakan Buyback',
        ]);

        $response->assertRedirect(route('shm.memorandums.buyback.index'));
        $this->assertDatabaseHas('shm_memorandums', [
            'shm_application_id' => $completedBuyback->id,
            'type' => ShmMemorandum::TYPE_BUYBACK,
            'status' => 'draft',
            'notes' => 'Catatan kelayakan Buyback',
        ]);
    }

    public function test_non_completed_buyback_applications_are_rejected(): void
    {
        $nonCompletedStatuses = [
            ShmApplicationStatus::Draft,
            ShmApplicationStatus::Submitted,
            ShmApplicationStatus::CreditChecking,
            ShmApplicationStatus::Memorandum,
            ShmApplicationStatus::Approval,
            ShmApplicationStatus::Approved,
            ShmApplicationStatus::DocumentCheck,
            ShmApplicationStatus::ReadyForDisbursement,
            ShmApplicationStatus::Disbursed,
            ShmApplicationStatus::Rejected,
            ShmApplicationStatus::Cancelled,
        ];

        foreach ($nonCompletedStatuses as $status) {
            $app = $this->createShmApplication(ShmApplication::TYPE_BUYBACK, $status);

            $response = $this->actingAs($this->admin)->post(route('shm.memorandums.buyback.store'), [
                'shm_application_id' => $app->id,
                'status' => 'draft',
                'notes' => 'Mencoba membuat dari status non-completed',
            ]);

            $response->assertSessionHasErrors('shm_application_id');
            $this->assertDatabaseMissing('shm_memorandums', [
                'shm_application_id' => $app->id,
                'type' => ShmMemorandum::TYPE_BUYBACK,
            ]);
        }
    }

    public function test_shgb_completed_application_is_rejected_for_buyback_creation(): void
    {
        $shgbCompleted = $this->createShmApplication(ShmApplication::TYPE_SHGB, ShmApplicationStatus::Completed);

        $response = $this->actingAs($this->admin)->post(route('shm.memorandums.buyback.store'), [
            'shm_application_id' => $shgbCompleted->id,
            'status' => 'draft',
            'notes' => 'Mencoba konversi silang SHGB ke Buyback',
        ]);

        $response->assertSessionHasErrors('shm_application_id');
        $this->assertDatabaseMissing('shm_memorandums', [
            'shm_application_id' => $shgbCompleted->id,
            'type' => ShmMemorandum::TYPE_BUYBACK,
        ]);
    }

    public function test_cannot_convert_same_buyback_application_more_than_once(): void
    {
        $completedBuyback = $this->createShmApplication(ShmApplication::TYPE_BUYBACK, ShmApplicationStatus::Completed);

        // Konversi pertama
        $this->actingAs($this->admin)->post(route('shm.memorandums.buyback.store'), [
            'shm_application_id' => $completedBuyback->id,
            'status' => 'draft',
            'notes' => 'Konversi pertama Buyback',
        ])->assertRedirect(route('shm.memorandums.buyback.index'));

        $this->assertEquals(1, ShmMemorandum::where('shm_application_id', $completedBuyback->id)->where('type', ShmMemorandum::TYPE_BUYBACK)->count());

        // Konversi kedua ditolak
        $secondResponse = $this->actingAs($this->admin)->post(route('shm.memorandums.buyback.store'), [
            'shm_application_id' => $completedBuyback->id,
            'status' => 'draft',
            'notes' => 'Konversi kedua (duplikat)',
        ]);

        $secondResponse->assertSessionHasErrors('shm_application_id');
        $this->assertEquals(1, ShmMemorandum::where('shm_application_id', $completedBuyback->id)->where('type', ShmMemorandum::TYPE_BUYBACK)->count());
    }

    public function test_buyback_index_only_lists_buyback_records(): void
    {
        $appShgb = $this->createShmApplication(ShmApplication::TYPE_SHGB, ShmApplicationStatus::Completed);
        $appBuyback = $this->createShmApplication(ShmApplication::TYPE_BUYBACK, ShmApplicationStatus::Completed);

        // 1 record SHM/SHGB dan 1 record Buyback
        ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'shm_application_id' => $appShgb->id,
            'created_by' => $this->admin->id,
            'status' => 'draft',
        ]);

        ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_BUYBACK,
            'shm_application_id' => $appBuyback->id,
            'created_by' => $this->admin->id,
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->admin)->get(route('shm.memorandums.buyback.index'));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Shm/Memorandum/BuybackIndex')
            ->has('items.data', 1)
            ->where('items.data.0.application_number', $appBuyback->application_number)
        );
    }

    public function test_buyback_create_page_only_lists_completed_and_unconverted_buyback_applications(): void
    {
        $eligibleBuyback = $this->createShmApplication(ShmApplication::TYPE_BUYBACK, ShmApplicationStatus::Completed);
        $draftBuyback = $this->createShmApplication(ShmApplication::TYPE_BUYBACK, ShmApplicationStatus::Draft);
        $completedShgb = $this->createShmApplication(ShmApplication::TYPE_SHGB, ShmApplicationStatus::Completed);
        $alreadyConvertedBuyback = $this->createShmApplication(ShmApplication::TYPE_BUYBACK, ShmApplicationStatus::Completed);

        ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_BUYBACK,
            'shm_application_id' => $alreadyConvertedBuyback->id,
            'created_by' => $this->admin->id,
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->admin)->get(route('shm.memorandums.buyback.create'));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Shm/Memorandum/BuybackCreate')
            ->has('applications', 1)
            ->where('applications.0.id', $eligibleBuyback->id)
        );
    }

    public function test_buyback_show_displays_memorandum_and_source_data(): void
    {
        $app = $this->createShmApplication(ShmApplication::TYPE_BUYBACK, ShmApplicationStatus::Completed);

        $memo = ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_BUYBACK,
            'shm_application_id' => $app->id,
            'created_by' => $this->admin->id,
            'status' => 'submitted',
            'notes' => 'Catatan Buyback show test',
            'submitted_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->get(route('shm.memorandums.buyback.show', $memo->id));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Shm/Memorandum/BuybackShow')
            ->where('memorandum.id', $memo->id)
            ->where('memorandum.type', 'buyback')
            ->where('memorandum.status', 'submitted')
            ->where('memorandum.notes', 'Catatan Buyback show test')
            ->where('application.id', $app->id)
            ->where('application.customer.name', $this->customer->name)
            ->has('properties', 1)
        );
    }

    public function test_buyback_edit_and_update(): void
    {
        $app = $this->createShmApplication(ShmApplication::TYPE_BUYBACK, ShmApplicationStatus::Completed);

        $memo = ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_BUYBACK,
            'shm_application_id' => $app->id,
            'created_by' => $this->admin->id,
            'status' => 'draft',
            'notes' => 'Catatan awal Buyback',
        ]);

        // Seed 5 dokumen legalitas buyback (Ticket 7 requirement for submitted)
        foreach (['shm', 'akta_buyback', 'kuasa_awal', 'ppjb', 'pengosongan'] as $type) {
            $memo->buybackDocuments()->create([
                'document_type' => $type,
                'file_name' => "{$type}.pdf",
                'file_path' => "test/{$type}.pdf",
                'mime_type' => 'application/pdf',
                'file_size' => 1024,
                'uploaded_by' => $this->admin->id,
            ]);
        }

        $response = $this->actingAs($this->admin)->get(route('shm.memorandums.buyback.edit', $memo->id));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Shm/Memorandum/BuybackEdit')
            ->where('memorandum.id', $memo->id)
            ->where('memorandum.application_number', $app->application_number)
        );

        $updateResponse = $this->actingAs($this->admin)->put(route('shm.memorandums.buyback.update', $memo->id), [
            'status' => 'submitted',
            'notes' => 'Catatan Buyback diperbarui',
        ]);

        $updateResponse->assertRedirect(route('shm.memorandums.buyback.show', $memo->id));
        $memo->refresh();
        $this->assertEquals('submitted', $memo->status);
        $this->assertEquals('Catatan Buyback diperbarui', $memo->notes);
        $this->assertNotNull($memo->submitted_at);

        // Pengajuan asal tidak berubah
        $app->refresh();
        $this->assertEquals(ShmApplicationStatus::Completed, $app->status);
        $this->assertEquals(ShmApplication::TYPE_BUYBACK, $app->type);
    }

    public function test_buyback_destroy_safely_deletes_memorandum_without_affecting_source_data(): void
    {
        $app = $this->createShmApplication(ShmApplication::TYPE_BUYBACK, ShmApplicationStatus::Completed);
        $propertyId = $app->properties()->first()->id;

        $memo = ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_BUYBACK,
            'shm_application_id' => $app->id,
            'created_by' => $this->admin->id,
            'status' => 'draft',
            'notes' => 'Data Buyback untuk dihapus',
        ]);

        $memoId = $memo->id;

        $deleteResponse = $this->actingAs($this->admin)->delete(route('shm.memorandums.buyback.destroy', $memo->id));
        $deleteResponse->assertRedirect(route('shm.memorandums.buyback.index'));

        // ShmMemorandum terhapus
        $this->assertDatabaseMissing('shm_memorandums', ['id' => $memoId]);

        // Pengajuan, Nasabah, Properti TETAP UTUH
        $this->assertDatabaseHas('shm_applications', ['id' => $app->id]);
        $this->assertDatabaseHas('shm_customers', ['id' => $this->customer->id]);
        $this->assertDatabaseHas('shm_properties', ['id' => $propertyId]);
    }

    public function test_authorization_checks_are_enforced_for_buyback(): void
    {
        $userWithoutPermission = User::factory()->create();
        $app = $this->createShmApplication(ShmApplication::TYPE_BUYBACK, ShmApplicationStatus::Completed);

        $this->actingAs($userWithoutPermission)
            ->get(route('shm.memorandums.buyback.index'))
            ->assertForbidden();

        $this->actingAs($userWithoutPermission)
            ->get(route('shm.memorandums.buyback.create'))
            ->assertForbidden();

        $this->actingAs($userWithoutPermission)
            ->post(route('shm.memorandums.buyback.store'), [
                'shm_application_id' => $app->id,
                'status' => 'draft',
            ])
            ->assertForbidden();
    }

    public function test_marketing_cannot_access_other_marketing_buyback_memorandum_idor_protection(): void
    {
        $app = $this->createShmApplication(ShmApplication::TYPE_BUYBACK, ShmApplicationStatus::Completed, $this->marketing);

        $memo = ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_BUYBACK,
            'shm_application_id' => $app->id,
            'created_by' => $this->marketing->id,
            'status' => 'draft',
        ]);

        $this->actingAs($this->otherMarketing)
            ->get(route('shm.memorandums.buyback.show', $memo->id))
            ->assertForbidden();

        $this->actingAs($this->otherMarketing)
            ->get(route('shm.memorandums.buyback.edit', $memo->id))
            ->assertForbidden();

        $this->actingAs($this->otherMarketing)
            ->put(route('shm.memorandums.buyback.update', $memo->id), [
                'status' => 'submitted',
                'notes' => 'Attempted hijack',
            ])
            ->assertForbidden();

        $this->actingAs($this->otherMarketing)
            ->delete(route('shm.memorandums.buyback.destroy', $memo->id))
            ->assertForbidden();
    }
}
