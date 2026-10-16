<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Member;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ShmApplication;
use App\Models\ShmCustomer;
use App\Models\User;
use App\Services\PermissionRegistry;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Ticket 46 — Master data Nasabah SHM.
 *
 * Menguji pemisahan nasabah SHM dari anggota koperasi: CRUD nasabah,
 * validasi, nomor yang dibuat server, pencarian & filter, otorisasi per
 * permission, audit satu event per aksi, serta jaminan bahwa modul SHM tidak
 * lagi bergantung pada tabel members.
 */
class ShmCustomerTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    private User $admin;

    private User $marketing;

    private User $pengawas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->superadmin = $this->userWithRole('superadmin');
        $this->admin = $this->userWithRole('admin');
        $this->marketing = $this->userWithRole('marketing');
        $this->pengawas = $this->userWithRole('pengawas');
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', $roleName)->value('id'));

        return $user;
    }

    /**
     * Pengguna dengan role khusus yang hanya memegang permission tertentu.
     *
     * @param  array<int, string>  $permissionNames
     */
    private function userWithPermissions(array $permissionNames): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $role = Role::query()->create([
            'name' => 't102-'.uniqid(),
            'display_name' => 'T102 Permission Tester',
            'is_system' => false,
        ]);

        $role->permissions()->attach(
            Permission::query()->whereIn('name', $permissionNames)->pluck('id'),
        );
        $user->roles()->attach($role->id);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $memberId = $overrides['member_id'] ?? null;
        if (! $memberId && ! array_key_exists('member_id', $overrides)) {
            $member = Member::factory()->create(['status' => 'active']);
            $memberId = $member->id;
        }

        return array_merge([
            'member_id' => $memberId,
            'name' => 'Nasabah Uji',
            'nik' => '3501234567890123',
            'phone' => '081234567890',
            'email' => 'nasabah@example.test',
            'address' => 'Jl. Contoh No. 1, Cirebon',
        ], $overrides);
    }

    private function createCustomerFor(User $user, array $overrides = []): ShmCustomer
    {
        $this->actingAs($user)
            ->post(route('shm.customers.store'), $this->payload($overrides))
            ->assertSessionHasNoErrors();

        return ShmCustomer::query()->latest('id')->firstOrFail();
    }

    // -----------------------------------------------------------------
    // A. Customer CRUD
    // -----------------------------------------------------------------

    public function test_marketing_can_create_customer(): void
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.customers.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $customer = ShmCustomer::query()->firstOrFail();

        $this->assertSame('Nasabah Uji', $customer->name);
        $this->assertSame('3501234567890123', $customer->nik);
        // Nasabah baru selalu dimulai sebagai aktif.
        $this->assertTrue($customer->is_active);
        $this->assertNotNull($customer->customer_number);
    }

    public function test_customer_number_is_generated_on_server(): void
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.customers.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $number = ShmCustomer::query()->value('customer_number');

        $this->assertNotNull($number);
        // Format resmi T102: `NNNNN-ANG` — sequence domain Nasabah SHM,
        // independen dari sequence anggota koperasi.
        $this->assertMatchesRegularExpression('/^\d{5}-ANG$/', $number);
    }

    public function test_customer_number_is_unique_across_many_rows(): void
    {
        $numbers = [];

        for ($i = 0; $i < 5; $i++) {
            $this->createCustomerFor($this->marketing, ['name' => "Nasabah {$i}"]);
            $numbers[] = ShmCustomer::query()->latest('id')->value('customer_number');
        }

        $this->assertCount(5, array_unique($numbers));
    }

    public function test_client_cannot_send_customer_number_or_status(): void
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.customers.store'), $this->payload([
                'customer_number' => 'NSB-PALSU',
                'is_active' => false,
            ]))
            ->assertSessionHasErrors('customer_number');

        $this->assertSame(0, ShmCustomer::query()->count());
    }

    public function test_customer_name_is_required(): void
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.customers.store'), $this->payload(['name' => '']))
            ->assertSessionHasErrors('name');

        $this->assertSame(0, ShmCustomer::query()->count());
    }

    public function test_customer_rejects_invalid_email(): void
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.customers.store'), $this->payload(['email' => 'bukan-email']))
            ->assertSessionHasErrors('email');
    }

    public function test_customer_rejects_phone_with_invalid_characters(): void
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.customers.store'), $this->payload(['phone' => '0812-ABC']))
            ->assertSessionHasErrors('phone');
    }

    public function test_customer_rejects_out_of_range_coordinates(): void
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.customers.store'), $this->payload([
                'latitude' => 120,
                'longitude' => 500,
            ]))
            ->assertSessionHasErrors(['latitude', 'longitude']);

        $this->assertSame(0, ShmCustomer::query()->count());
    }

    public function test_customer_requires_paired_coordinates(): void
    {
        $this->actingAs($this->marketing)
            ->post(route('shm.customers.store'), $this->payload([
                'latitude' => -6.732,
            ]))
            ->assertSessionHasErrors('latitude');
    }

    public function test_customer_coordinates_are_optional(): void
    {
        $customer = $this->createCustomerFor($this->marketing);

        $this->assertFalse($customer->hasLocation());
        $this->assertNull($customer->latitude);
    }

    public function test_customer_can_be_updated(): void
    {
        $customer = $this->createCustomerFor($this->marketing);

        $this->actingAs($this->marketing)
            ->put(route('shm.customers.update', $customer->id), $this->payload([
                'name' => 'Nasabah Diubah',
                'phone' => '089999999999',
            ]))
            ->assertSessionHasNoErrors();

        $customer->refresh();

        $this->assertSame('Nasabah Diubah', $customer->name);
        $this->assertSame('089999999999', $customer->phone);
        // Nomor nasabah tidak ikut berubah karena dibuat server (T102: NNNNN-ANG).
        $this->assertMatchesRegularExpression('/^\d{5}-ANG$/', $customer->customer_number);
    }

    public function test_customer_status_can_be_toggled(): void
    {
        $customer = $this->createCustomerFor($this->marketing);

        $this->actingAs($this->marketing)
            ->patch(route('shm.customers.toggle-active', $customer->id), ['is_active' => false])
            ->assertSessionHasNoErrors();

        $this->assertFalse($customer->refresh()->is_active);

        // Nasabah nonaktif TIDAK dihapus; barisnya tetap ada.
        $this->assertDatabaseHas('shm_customers', ['id' => $customer->id]);

        $this->actingAs($this->marketing)
            ->patch(route('shm.customers.toggle-active', $customer->id), ['is_active' => true])
            ->assertSessionHasNoErrors();

        $this->assertTrue($customer->refresh()->is_active);
    }

    public function test_customer_index_lists_customers(): void
    {
        $customer = $this->createCustomerFor($this->marketing);

        $this->actingAs($this->marketing)
            ->get(route('shm.customers.index'))
            ->assertOk()
            ->assertSee($customer->customer_number);
    }

    public function test_customer_index_can_be_searched(): void
    {
        $this->createCustomerFor($this->marketing, ['name' => 'Budi Santoso']);
        $this->createCustomerFor($this->marketing, ['name' => 'Siti Aminah']);

        $this->actingAs($this->marketing)
            ->get(route('shm.customers.index', ['search' => 'Budi']))
            ->assertOk()
            ->assertSee('Budi Santoso')
            ->assertDontSee('Siti Aminah');
    }

    public function test_customer_index_can_be_filtered_by_status(): void
    {
        $active = $this->createCustomerFor($this->marketing, ['name' => 'Nasabah Aktif']);
        $inactive = ShmCustomer::factory()->inactive()->create(['name' => 'Nasabah Nonaktif']);

        $this->assertTrue($active->is_active);

        $this->actingAs($this->marketing)
            ->get(route('shm.customers.index', ['status' => 'inactive']))
            ->assertOk()
            ->assertSee('Nasabah Nonaktif')
            ->assertDontSee('Nasabah Aktif');
    }

    public function test_customer_detail_page_renders(): void
    {
        $customer = $this->createCustomerFor($this->marketing);

        $this->actingAs($this->marketing)
            ->get(route('shm.customers.show', $customer->id))
            ->assertOk();
    }

    // -----------------------------------------------------------------
    // B. Authorization
    // -----------------------------------------------------------------

    public function test_admin_can_manage_customers(): void
    {
        $this->actingAs($this->admin)
            ->get(route('shm.customers.index'))
            ->assertOk();

        $this->actingAs($this->admin)
            ->post(route('shm.customers.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertSame(1, ShmCustomer::query()->count());
    }

    public function test_superadmin_follows_existing_bypass(): void
    {
        $this->actingAs($this->superadmin)
            ->get(route('shm.customers.index'))
            ->assertOk();

        $this->actingAs($this->superadmin)
            ->post(route('shm.customers.store'), $this->payload())
            ->assertSessionHasNoErrors();
    }

    public function test_pengawas_has_no_customer_access(): void
    {
        $this->actingAs($this->pengawas)
            ->get(route('shm.customers.index'))
            ->assertForbidden();
    }

    public function test_marketing_role_holds_customer_permissions(): void
    {
        $this->assertTrue($this->marketing->hasPermission('shm.customer.view'));
        $this->assertTrue($this->marketing->hasPermission('shm.customer.create'));
        $this->assertTrue($this->marketing->hasPermission('shm.customer.update'));

        // Penghapusan permanen (T102) SENGAJA tidak masuk role marketing:
        // authority penghapusan master nasabah belum diputuskan KSP.
        $this->assertFalse($this->marketing->hasPermission('shm.customer.delete'));

        // Batas kewenangan lain tidak ikut terbuka.
        $this->assertFalse($this->marketing->hasPermission('shm.view_all'));
        $this->assertFalse($this->marketing->hasPermission('shm.approval.decide'));
    }

    // -----------------------------------------------------------------
    // B2. Permission per-aksi (T102): create/update tanpa permission = 403
    // -----------------------------------------------------------------

    public function test_customer_create_without_specific_permission_is_forbidden(): void
    {
        $member = Member::factory()->create(['status' => 'active']);
        $user = $this->userWithPermissions(['shm.customer.view']);

        $this->actingAs($user)
            ->post(route('shm.customers.store'), $this->payload(['member_id' => $member->id]))
            ->assertForbidden();

        $this->assertSame(0, ShmCustomer::query()->count());
    }

    public function test_customer_update_without_specific_permission_is_forbidden(): void
    {
        $customer = $this->createCustomerFor($this->marketing);
        $user = $this->userWithPermissions(['shm.customer.view']);

        $this->actingAs($user)
            ->put(route('shm.customers.update', $customer->id), $this->payload([
                'name' => 'Tidak Boleh',
            ]))
            ->assertForbidden();

        $this->assertSame('Nasabah Uji', $customer->refresh()->name);
    }

    public function test_customer_delete_permission_is_registered_and_configurable_via_role_ui(): void
    {
        // Terdaftar di registry config dan tersinkron di tabel permissions.
        $this->assertTrue(app(PermissionRegistry::class)->isValid('shm.customer.delete'));
        $this->assertDatabaseHas('permissions', ['name' => 'shm.customer.delete']);

        // Tampil di payload UI Role & Permission.
        $this->actingAs($this->admin)
            ->get(route('pengaturan.roles.permissions'))
            ->assertOk()
            ->assertSee('shm.customer.delete');

        // Grant lewat endpoint UI yang sama → efektif.
        $tester = $this->userWithPermissions([]);
        $role = $tester->roles()->firstOrFail();

        $this->actingAs($this->admin)
            ->put(route('pengaturan.roles.update', $role->id), [
                'display_name' => $role->display_name,
                'description' => $role->description,
                'permissions' => ['shm.customer.delete'],
            ])
            ->assertSessionHas('success');

        $this->assertTrue($tester->fresh()->hasPermission('shm.customer.delete'));

        // Revoke lewat endpoint yang sama → hilang.
        $this->actingAs($this->admin)
            ->put(route('pengaturan.roles.update', $role->id), [
                'display_name' => $role->display_name,
                'description' => $role->description,
                'permissions' => [],
            ])
            ->assertSessionHas('success');

        $this->assertFalse($tester->fresh()->hasPermission('shm.customer.delete'));
    }

    // -----------------------------------------------------------------
    // C. Data integrity: pemisahan dari anggota koperasi
    // -----------------------------------------------------------------

    public function test_customer_table_does_not_reference_members(): void
    {
        $this->assertTrue(Schema::hasTable('shm_customers'));

        // T89C: shm_customers kini menyimpan foreign key member_id ke members
        $this->assertTrue(
            Schema::hasColumn('shm_customers', 'member_id'),
            'shm_customers harus memiliki kolom member_id.'
        );

        foreach (['amount', 'interest_rate', 'margin', 'discount', 'penalty'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('shm_customers', $column),
                "shm_customers tidak boleh punya kolom {$column}."
            );
        }
    }

    public function test_existing_member_data_is_untouched_by_customer_operations(): void
    {
        $member = Member::factory()->create(['full_name' => 'Anggota Koperasi Lama', 'status' => 'active']);
        $before = Member::query()->count();

        // Operasi pembuatan nasabah SHM menggunakan anggota yang sudah ada tanpa menambah record member baru
        $this->createCustomerFor($this->marketing, ['member_id' => $member->id]);

        $this->assertSame($before, Member::query()->count());
        $this->assertSame('Anggota Koperasi Lama', $member->refresh()->full_name);
    }

    public function test_customer_detail_only_shows_own_applications_to_marketing(): void
    {
        $otherMarketing = $this->userWithRole('marketing');
        $customer = $this->createCustomerFor($this->marketing);

        // Pengajuan milik Marketing lain untuk nasabah yang sama.
        $this->actingAs($otherMarketing)
            ->post(route('shm.applications.store'), [
                'shm_customer_id' => $customer->id,
                'notes' => 'Pengajuan marketing lain',
            ])
            ->assertSessionHasNoErrors();

        $otherApplication = ShmApplication::query()->latest('id')->firstOrFail();

        // Pemilik sesi hanya melihat pengajuannya sendiri untuk nasabah ini.
        $this->actingAs($this->marketing)
            ->get(route('shm.customers.show', $customer->id))
            ->assertOk()
            ->assertDontSee($otherApplication->application_number);

        // Admin dengan shm.view_all melihat seluruh pengajuan nasabah tersebut.
        $this->actingAs($this->admin)
            ->get(route('shm.customers.show', $customer->id))
            ->assertOk()
            ->assertSee($otherApplication->application_number);
    }

    // -----------------------------------------------------------------
    // D. Audit
    // -----------------------------------------------------------------

    public function test_customer_creation_is_audited_once(): void
    {
        $this->createCustomerFor($this->marketing);

        $this->assertSame(1, AuditLog::query()
            ->where('action', 'shm.customer.created')
            ->count());
    }

    public function test_customer_update_is_audited_once(): void
    {
        $customer = $this->createCustomerFor($this->marketing);

        $this->actingAs($this->marketing)
            ->put(route('shm.customers.update', $customer->id), $this->payload([
                'name' => 'Nama Baru',
            ]));

        $this->assertSame(1, AuditLog::query()
            ->where('action', 'shm.customer.updated')
            ->count());
    }

    public function test_customer_status_change_is_audited_once(): void
    {
        $customer = $this->createCustomerFor($this->marketing);

        $this->actingAs($this->marketing)
            ->patch(route('shm.customers.toggle-active', $customer->id), ['is_active' => false]);

        $this->assertSame(1, AuditLog::query()
            ->where('action', 'shm.customer.status_changed')
            ->count());
    }
}
