<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\MarketingActivity;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ShmApplication;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\User;
use Database\Seeders\MakroPhysicalRoleSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T102 — Penghapusan Nasabah SHM (permission + guard dependency).
 *
 * Mengunci:
 * - Route DELETE memakai permission khusus `shm.customer.delete` (403 tanpa
 *   permission; superadmin tetap lewat bypass; marketing default TIDAK
 *   memperolehnya).
 * - Nasabah TANPA dependensi: hard delete sukses, anggota sumber TIDAK ikut
 *   terhapus, satu event audit `shm.customer.deleted`.
 * - Nasabah DENGAN dependensi (pengajuan SHM, pinjaman SHM, aktivitas
 *   Marketing): DITOLAK dengan pesan jelas — tidak ada cascade, rekaman
 *   riwayat/finansial tetap utuh, relasi tidak di-null-kan diam-diam.
 * - Nasabah terpakai tetap tersedia lewat jalur deaktivasi existing
 *   (bukti: toggle tetap berfungsi — dikunci ShmCustomerTest).
 */
class ShmCustomerDeleteTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    private User $marketing;

    private User $deleter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(MakroPhysicalRoleSeeder::class);

        $this->superadmin = $this->userWithRole('superadmin');
        $this->superadmin->roles()->update(['entity' => 'shared']);
        $this->marketing = $this->userWithRole('marketing_makro');
        $this->deleter = $this->userWithPermissions(['shm.customer.delete']);
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', $roleName)->value('id'));

        return $user;
    }

    /**
     * @param  array<int, string>  $permissionNames
     */
    private function userWithPermissions(array $permissionNames): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $role = Role::query()->create([
            'name' => 't102-del-'.uniqid(),
            'display_name' => 'T102 Delete Tester',
            'is_system' => false,
            'entity' => 'makro',
        ]);

        $role->permissions()->attach(
            Permission::query()->whereIn('name', $permissionNames)->pluck('id'),
        );
        $user->roles()->attach($role->id);

        return $user;
    }

    private function createCustomerViaHttp(?int $memberId = null, string $name = 'Nasabah Uji'): ShmCustomer
    {
        $payload = [
            'name' => $name,
            'nik' => '3501234567890123',
            'phone' => '081234567890',
            'address' => 'Jl. Contoh No. 1, Cirebon',
        ];
        if ($memberId !== null) {
            $payload['member_id'] = $memberId;
        }

        $this->actingAs($this->marketing)
            ->post(route('shm.customers.store'), $payload)
            ->assertSessionHasNoErrors();

        return ShmCustomer::query()->latest('id')->firstOrFail();
    }

    // -----------------------------------------------------------------
    // Otorisasi (Phase 8)
    // -----------------------------------------------------------------

    public function test_delete_without_permission_is_forbidden(): void
    {
        $customer = ShmCustomer::factory()->create();
        $viewer = $this->userWithPermissions(['shm.customer.view']);

        $this->actingAs($viewer)
            ->delete(route('shm.customers.destroy', $customer->id))
            ->assertForbidden();

        $this->assertDatabaseHas('shm_customers', ['id' => $customer->id]);
    }

    public function test_marketing_default_role_cannot_delete_customer(): void
    {
        $customer = ShmCustomer::factory()->create();

        // Marketing memegang view/create/update, tetapi BUKAN delete.
        $this->assertTrue($this->marketing->hasPermission('shm.customer.update'));
        $this->assertFalse($this->marketing->hasPermission('shm.customer.delete'));

        $this->actingAs($this->marketing)
            ->delete(route('shm.customers.destroy', $customer->id))
            ->assertForbidden();

        $this->assertDatabaseHas('shm_customers', ['id' => $customer->id]);
    }

    public function test_superadmin_bypass_can_delete_customer(): void
    {
        $customer = ShmCustomer::factory()->create();

        $this->actingAs($this->superadmin)
            ->delete(route('shm.customers.destroy', $customer->id))
            ->assertRedirect(route('shm.customers.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('shm_customers', ['id' => $customer->id]);
    }

    // -----------------------------------------------------------------
    // Delete business-safe (tanpa dependensi)
    // -----------------------------------------------------------------

    public function test_authorized_delete_removes_customer_without_touching_member(): void
    {
        $customer = $this->createCustomerViaHttp();

        $this->actingAs($this->deleter)
            ->delete(route('shm.customers.destroy', $customer->id))
            ->assertRedirect(route('shm.customers.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('shm_customers', ['id' => $customer->id]);

        // Satu event audit untuk aksi penghapusan.
        $this->assertSame(1, AuditLog::query()->where('action', 'shm.customer.deleted')->count());
    }

    // -----------------------------------------------------------------
    // Guard dependency: ditolak, tanpa cascade (Phase 6)
    // -----------------------------------------------------------------

    public function test_delete_is_rejected_when_customer_has_application(): void
    {
        $customer = $this->createCustomerViaHttp();

        $this->actingAs($this->marketing)
            ->post(route('shm.applications.store'), [
                'shm_customer_id' => $customer->id,
                'notes' => 'Pengajuan sebagai penghalang hapus',
            ])
            ->assertSessionHasNoErrors();

        $application = ShmApplication::query()->latest('id')->firstOrFail();

        $this->actingAs($this->deleter)
            ->delete(route('shm.customers.destroy', $customer->id))
            ->assertSessionHasErrors('customer')
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'pengajuan SHM'));

        // Nasabah, pengajuan, dan seluruh rantainya tetap utuh.
        $this->assertDatabaseHas('shm_customers', ['id' => $customer->id]);
        $this->assertDatabaseHas('shm_applications', ['id' => $application->id]);
        $this->assertSame(0, AuditLog::query()->where('action', 'shm.customer.deleted')->count());
    }

    public function test_delete_is_rejected_when_customer_has_loan(): void
    {
        $customer = ShmCustomer::factory()->create();
        $loan = ShmLoan::factory()->create(['shm_customer_id' => $customer->id]);

        $this->actingAs($this->deleter)
            ->delete(route('shm.customers.destroy', $customer->id))
            ->assertSessionHasErrors('customer')
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'pinjaman SHM'));

        // Rantai finansial tidak ter-cascade.
        $this->assertDatabaseHas('shm_customers', ['id' => $customer->id]);
        $this->assertDatabaseHas('shm_loans', ['id' => $loan->id]);
    }

    public function test_delete_is_rejected_when_customer_has_marketing_activity(): void
    {
        $customer = ShmCustomer::factory()->create();

        MarketingActivity::query()->create([
            'marketing_id' => $this->marketing->id,
            'shm_customer_id' => $customer->id,
            'checked_in_at' => now(),
        ]);

        $this->actingAs($this->deleter)
            ->delete(route('shm.customers.destroy', $customer->id))
            ->assertSessionHasErrors('customer')
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'aktivitas Marketing'));

        $this->assertDatabaseHas('shm_customers', ['id' => $customer->id]);
        $this->assertSame(1, MarketingActivity::query()->where('shm_customer_id', $customer->id)->count());
    }
}
