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
 * Feature Test untuk Ticket 2 — SHM Memorandum: SHM/SHGB Data Management & Application Integration.
 *
 * Menguji:
 * 1. Eligibility Check: Hanya pengajuan dengan status Completed yang dapat dikonversi ke SHM/SHGB.
 *    Status Draft, CreditChecking, Memorandum, Approval, Ready, Disbursed ditolak secara ketat.
 * 2. 1:1 Relationship & Duplicate Prevention: Pengajuan yang sudah dikonversi ke SHM/SHGB tidak dapat dikonversi lagi.
 * 3. Full CRUD: Index, Create (Picker filtering), Show (Detail dengan konteks pengajuan/nasabah/properti), Edit, Update, Destroy.
 * 4. Historical Data Preservation: Penghapusan SHM/SHGB tidak menghapus ShmApplication, ShmCustomer, atau ShmProperty.
 * 5. Security & Authorization: Hak akses shm.memorandum.update ditegakkan (403 bila unauthorized), IDOR protection.
 */
class ShmMemorandumShmShgbManagementTest extends TestCase
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
            'customer_number' => 'CUST-MEMO-001',
            'name' => 'Budi Santoso',
            'nik' => '3201000000000099',
            'phone' => '081234567899',
            'is_active' => true,
        ]);
    }

    /**
     * Helper untuk membuat ShmApplication dengan status tertentu.
     */
    private function createShmApplication(ShmApplicationStatus $status, ?User $marketingUser = null): ShmApplication
    {
        $app = ShmApplication::create([
            'application_number' => 'APP-SHM-'.uniqid(),
            'shm_customer_id' => $this->customer->id,
            'marketing_id' => ($marketingUser ?? $this->marketing)->id,
            'status' => $status,
            'completed_at' => $status === ShmApplicationStatus::Completed ? now() : null,
        ]);

        ShmProperty::create([
            'shm_application_id' => $app->id,
            'certificate_type' => 'SHM',
            'certificate_number' => 'SHM-12345-'.uniqid(),
            'owner_name' => $this->customer->name,
            'address' => 'Jl. Merdeka No. 45, Bandung',
        ]);

        return $app;
    }

    public function test_only_completed_application_is_eligible_for_shm_shgb_creation(): void
    {
        $completedApp = $this->createShmApplication(ShmApplicationStatus::Completed);

        // Request konversi pengajuan Completed harus berhasil (302 redirect)
        $response = $this->actingAs($this->admin)->post(route('shm.memorandums.shm-shgb.store'), [
            'shm_application_id' => $completedApp->id,
            'status' => 'draft',
            'notes' => 'Catatan kelayakan SHM',
        ]);

        $response->assertRedirect(route('shm.memorandums.shm-shgb.index'));
        $this->assertDatabaseHas('shm_memorandums', [
            'shm_application_id' => $completedApp->id,
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'status' => 'draft',
            'notes' => 'Catatan kelayakan SHM',
        ]);
    }

    public function test_non_completed_applications_are_rejected(): void
    {
        $nonCompletedStatuses = [
            ShmApplicationStatus::Draft,
            ShmApplicationStatus::Submitted,
            ShmApplicationStatus::CreditChecking,
            ShmApplicationStatus::Memorandum,
            ShmApplicationStatus::Approval,
            ShmApplicationStatus::Approved,
            ShmApplicationStatus::ReadyForDisbursement,
            ShmApplicationStatus::Disbursed,
            ShmApplicationStatus::Rejected,
            ShmApplicationStatus::Cancelled,
        ];

        foreach ($nonCompletedStatuses as $status) {
            $app = $this->createShmApplication($status);

            $response = $this->actingAs($this->admin)->post(route('shm.memorandums.shm-shgb.store'), [
                'shm_application_id' => $app->id,
                'status' => 'draft',
                'notes' => 'Mencoba membuat dari status non-completed',
            ]);

            // FormRequest after hook menghasilkan session errors pada shm_application_id
            $response->assertSessionHasErrors('shm_application_id');
            $this->assertDatabaseMissing('shm_memorandums', [
                'shm_application_id' => $app->id,
                'type' => ShmMemorandum::TYPE_SHM_SHGB,
            ]);
        }
    }

    public function test_cannot_convert_same_application_more_than_once_duplicate_protection(): void
    {
        $completedApp = $this->createShmApplication(ShmApplicationStatus::Completed);

        // Konversi pertama
        $this->actingAs($this->admin)->post(route('shm.memorandums.shm-shgb.store'), [
            'shm_application_id' => $completedApp->id,
            'status' => 'draft',
            'notes' => 'Konversi pertama',
        ])->assertRedirect(route('shm.memorandums.shm-shgb.index'));

        $this->assertEquals(1, ShmMemorandum::where('shm_application_id', $completedApp->id)->where('type', ShmMemorandum::TYPE_SHM_SHGB)->count());

        // Konversi kedua untuk pengajuan yang sama harus ditolak
        $secondResponse = $this->actingAs($this->admin)->post(route('shm.memorandums.shm-shgb.store'), [
            'shm_application_id' => $completedApp->id,
            'status' => 'draft',
            'notes' => 'Mencoba konversi duplikat',
        ]);

        $secondResponse->assertSessionHasErrors('shm_application_id');
        $this->assertEquals(1, ShmMemorandum::where('shm_application_id', $completedApp->id)->where('type', ShmMemorandum::TYPE_SHM_SHGB)->count());
    }

    public function test_shm_shgb_index_only_lists_shm_shgb_records(): void
    {
        $app1 = $this->createShmApplication(ShmApplicationStatus::Completed);
        $app2 = $this->createShmApplication(ShmApplicationStatus::Completed);

        // Buat satu record shm_shgb dan satu record buyback
        ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'shm_application_id' => $app1->id,
            'created_by' => $this->admin->id,
            'status' => 'draft',
        ]);

        ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_BUYBACK,
            'shm_application_id' => $app2->id,
            'created_by' => $this->admin->id,
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->admin)->get(route('shm.memorandums.shm-shgb.index'));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Shm/Memorandum/ShmShgbIndex')
            ->has('items.data', 1)
            ->where('items.data.0.application_number', $app1->application_number)
        );
    }

    public function test_shm_shgb_create_page_only_lists_completed_and_unconverted_applications(): void
    {
        $completedApp = $this->createShmApplication(ShmApplicationStatus::Completed);
        $draftApp = $this->createShmApplication(ShmApplicationStatus::Draft);
        $alreadyConvertedApp = $this->createShmApplication(ShmApplicationStatus::Completed);

        ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'shm_application_id' => $alreadyConvertedApp->id,
            'created_by' => $this->admin->id,
            'status' => 'draft',
        ]);

        $response = $this->actingAs($this->admin)->get(route('shm.memorandums.shm-shgb.create'));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Shm/Memorandum/ShmShgbCreate')
            ->has('applications', 1)
            ->where('applications.0.id', $completedApp->id)
        );
    }

    public function test_shm_shgb_show_displays_memorandum_and_source_data(): void
    {
        $app = $this->createShmApplication(ShmApplicationStatus::Completed);

        $memo = ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'shm_application_id' => $app->id,
            'created_by' => $this->admin->id,
            'status' => 'submitted',
            'notes' => 'Catatan show test',
            'submitted_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->get(route('shm.memorandums.shm-shgb.show', $memo->id));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Shm/Memorandum/ShmShgbShow')
            ->where('memorandum.id', $memo->id)
            ->where('memorandum.type', 'shm_shgb')
            ->where('memorandum.status', 'submitted')
            ->where('memorandum.notes', 'Catatan show test')
            ->where('application.id', $app->id)
            ->where('application.customer.name', $this->customer->name)
            ->has('properties', 1)
        );
    }

    public function test_shm_shgb_edit_and_update(): void
    {
        $app = $this->createShmApplication(ShmApplicationStatus::Completed);

        $memo = ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'shm_application_id' => $app->id,
            'created_by' => $this->admin->id,
            'status' => 'draft',
            'notes' => 'Catatan awal',
        ]);

        // Cek halaman edit
        $response = $this->actingAs($this->admin)->get(route('shm.memorandums.shm-shgb.edit', $memo->id));
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Shm/Memorandum/ShmShgbEdit')
            ->where('memorandum.id', $memo->id)
            ->where('memorandum.application_number', $app->application_number)
        );

        // Update memorandum
        $updateResponse = $this->actingAs($this->admin)->put(route('shm.memorandums.shm-shgb.update', $memo->id), [
            'status' => 'submitted',
            'notes' => 'Catatan diperbarui',
        ]);

        $updateResponse->assertRedirect(route('shm.memorandums.shm-shgb.show', $memo->id));
        $memo->refresh();
        $this->assertEquals('submitted', $memo->status);
        $this->assertEquals('Catatan diperbarui', $memo->notes);
        $this->assertNotNull($memo->submitted_at);

        // Verifikasi pengajuan asal TIDAK berubah
        $app->refresh();
        $this->assertEquals(ShmApplicationStatus::Completed, $app->status);
    }

    public function test_shm_shgb_destroy_safely_deletes_memorandum_without_affecting_source_data(): void
    {
        $app = $this->createShmApplication(ShmApplicationStatus::Completed);
        $propertyId = $app->properties()->first()->id;

        $memo = ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'shm_application_id' => $app->id,
            'created_by' => $this->admin->id,
            'status' => 'draft',
            'notes' => 'Data untuk dihapus',
        ]);

        $memoId = $memo->id;

        $deleteResponse = $this->actingAs($this->admin)->delete(route('shm.memorandums.shm-shgb.destroy', $memo->id));
        $deleteResponse->assertRedirect(route('shm.memorandums.shm-shgb.index'));

        // ShmMemorandum harus terhapus
        $this->assertDatabaseMissing('shm_memorandums', ['id' => $memoId]);

        // HISTORICAL INTEGRITY: ShmApplication, ShmCustomer, ShmProperty TETAP UTUH
        $this->assertDatabaseHas('shm_applications', ['id' => $app->id]);
        $this->assertDatabaseHas('shm_customers', ['id' => $this->customer->id]);
        $this->assertDatabaseHas('shm_properties', ['id' => $propertyId]);
    }

    public function test_authorization_checks_are_enforced(): void
    {
        $userWithoutPermission = User::factory()->create();
        $app = $this->createShmApplication(ShmApplicationStatus::Completed);

        // User tanpa hak akses ditolak 403
        $this->actingAs($userWithoutPermission)
            ->get(route('shm.memorandums.shm-shgb.index'))
            ->assertForbidden();

        $this->actingAs($userWithoutPermission)
            ->get(route('shm.memorandums.shm-shgb.create'))
            ->assertForbidden();

        $this->actingAs($userWithoutPermission)
            ->post(route('shm.memorandums.shm-shgb.store'), [
                'shm_application_id' => $app->id,
                'status' => 'draft',
            ])
            ->assertForbidden();
    }

    public function test_marketing_cannot_access_other_marketing_memorandum_idor_protection(): void
    {
        // App dibuat oleh marketing A
        $app = $this->createShmApplication(ShmApplicationStatus::Completed, $this->marketing);

        $memo = ShmMemorandum::create([
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'shm_application_id' => $app->id,
            'created_by' => $this->marketing->id,
            'status' => 'draft',
        ]);

        // Marketing B (otherMarketing) tanpa shm.view_all mencoba akses show / edit / update / destroy
        $this->actingAs($this->otherMarketing)
            ->get(route('shm.memorandums.shm-shgb.show', $memo->id))
            ->assertForbidden();

        $this->actingAs($this->otherMarketing)
            ->get(route('shm.memorandums.shm-shgb.edit', $memo->id))
            ->assertForbidden();

        $this->actingAs($this->otherMarketing)
            ->put(route('shm.memorandums.shm-shgb.update', $memo->id), [
                'status' => 'submitted',
                'notes' => 'Attempted hijack',
            ])
            ->assertForbidden();

        $this->actingAs($this->otherMarketing)
            ->delete(route('shm.memorandums.shm-shgb.destroy', $memo->id))
            ->assertForbidden();
    }
}
