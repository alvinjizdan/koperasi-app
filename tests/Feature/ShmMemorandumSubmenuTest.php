<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\ShmApplication;
use App\Models\ShmCustomer;
use App\Models\ShmMemorandum;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShmMemorandumSubmenuTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $marketing;

    private ShmApplication $application;

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

        $customer = ShmCustomer::create([
            'customer_number' => 'CUST-001',
            'name' => 'Nasabah Uji Memorandum',
            'nik' => '3201000000000001',
            'phone' => '081234567890',
            'is_active' => true,
        ]);

        $this->application = ShmApplication::create([
            'application_number' => 'APP-MEMO-001',
            'type' => 'shgb',
            'shm_customer_id' => $customer->id,
            'marketing_id' => $this->marketing->id,
            'status' => 'memorandum',
        ]);

        $buybackApp = ShmApplication::create([
            'application_number' => 'APP-MEMO-002',
            'type' => 'buyback',
            'shm_customer_id' => $customer->id,
            'marketing_id' => $this->marketing->id,
            'status' => 'memorandum',
        ]);

        // Seed sample memorandum for SHM/SHGB and Buyback
        ShmMemorandum::create([
            'shm_application_id' => $this->application->id,
            'type' => ShmMemorandum::TYPE_SHM_SHGB,
            'status' => ShmMemorandum::STATUS_SUBMITTED,
            'notes' => 'Catatan Memorandum SHM/SHGB',
            'created_by' => $this->marketing->id,
        ]);

        ShmMemorandum::create([
            'shm_application_id' => $buybackApp->id,
            'type' => ShmMemorandum::TYPE_BUYBACK,
            'status' => ShmMemorandum::STATUS_DRAFT,
            'notes' => 'Catatan Memorandum Buyback',
            'created_by' => $this->marketing->id,
        ]);
    }

    /** 1. Halaman Memorandum SHM/SHGB dapat diakses dan menampilkan data kategori SHM/SHGB */
    public function test_shm_shgb_submenu_accessible(): void
    {
        $response = $this->actingAs($this->admin)->get(route('shm.memorandums.shm-shgb.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Shm/Memorandum/ShmShgbIndex')
            ->has('items.data', 1)
            ->where('items.data.0.status', ShmMemorandum::STATUS_SUBMITTED)
        );
    }

    /** 2. Halaman Memorandum Buyback dapat diakses dan menampilkan data kategori Buyback */
    public function test_buyback_submenu_accessible(): void
    {
        $response = $this->actingAs($this->admin)->get(route('shm.memorandums.buyback.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Shm/Memorandum/BuybackIndex')
            ->has('items.data', 1)
            ->where('items.data.0.status', ShmMemorandum::STATUS_DRAFT)
        );
    }

    /** 3. Halaman Summary Memorandum dapat diakses dan menampilkan kalkulasi statistik gabungan */
    public function test_summary_submenu_accessible_with_correct_stats(): void
    {
        $response = $this->actingAs($this->admin)->get(route('shm.memorandums.summary.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Shm/Memorandum/SummaryIndex')
            ->where('stats.total_shm_shgb', 1)
            ->where('stats.submitted_shm_shgb', 1)
            ->where('stats.draft_shm_shgb', 0)
            ->where('stats.total_buyback', 1)
            ->where('stats.draft_buyback', 1)
            ->where('stats.submitted_buyback', 0)
            ->where('stats.grand_total', 2)
        );
    }

    /** 4. User tanpa permission shm.memorandum.update ditolak (403 Forbidden) */
    public function test_unauthorized_user_cannot_access_memorandum_submenus(): void
    {
        $unauthorized = User::factory()->create();

        $this->actingAs($unauthorized)->get(route('shm.memorandums.shm-shgb.index'))->assertForbidden();
        $this->actingAs($unauthorized)->get(route('shm.memorandums.buyback.index'))->assertForbidden();
        $this->actingAs($unauthorized)->get(route('shm.memorandums.summary.index'))->assertForbidden();
    }

    /** 5. Halaman Pengajuan SHGB tetap berfungsi pada route existing */
    public function test_pengajuan_shgb_remains_fully_functional(): void
    {
        $response = $this->actingAs($this->admin)->get(route('shm.applications.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Shm/Applications/Index')
            ->has('applications.data')
        );
    }
}
