<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * TICKET — ROLE MANAGEMENT & USER CRUD
 *
 * Verifikasi komprehensif untuk:
 * 1. Role Deletion (Kasir dan Pengawas benar-benar tidak ada di database dan tidak dapat diassign).
 * 2. Role Activation (President Director / direktur_utama aktif dan mempertahankan hak approval).
 * 3. Role Creation (Branch Manager / branch_manager aktif dengan permission matrix yang sesuai).
 * 4. Superadmin protection (tidak dapat dihapus, tidak dapat 0 Superadmin aktif).
 * 5. User CRUD (List, Create, Show, Edit, Update, Toggle Active/Deactivate).
 * 6. Password security (Hashing, update password opsional, konfirmasi password).
 * 7. Modul Kasir & Audit Trail tetap berfungsi penuh walau role kasir & pengawas dihapus.
 */
class RoleManagementAndUserCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->superadmin = User::factory()->create(['name' => 'Superadmin Koperasi', 'is_active' => true]);
        $this->superadmin->roles()->attach(Role::where('name', 'superadmin')->value('id'));

        $this->admin = User::factory()->create(['name' => 'Admin Koperasi', 'is_active' => true]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->value('id'));
    }

    // =========================================================================
    // 1. ROLE STRUCTURE & DELETION
    // =========================================================================

    public function test_target_roles_exist_and_deleted_roles_do_not_exist(): void
    {
        // Role yang wajib ada dan aktif:
        $activeRoles = ['superadmin', 'admin', 'direktur_utama', 'branch_manager', 'marketing'];
        foreach ($activeRoles as $roleName) {
            $role = Role::where('name', $roleName)->first();
            $this->assertNotNull($role, "Role {$roleName} harus ada.");
            $this->assertTrue($role->is_active, "Role {$roleName} harus aktif.");
        }

        // Role yang wajib dihapus (DELETE, bukan inactive):
        $deletedRoles = ['kasir', 'pengawas'];
        foreach ($deletedRoles as $roleName) {
            $this->assertNull(Role::where('name', $roleName)->first(), "Role {$roleName} harus terhapus permanen.");
        }
    }

    public function test_deleted_roles_cannot_be_assigned_to_user(): void
    {
        // Request assign role kasir (id fiktif / null) ditolak
        $response = $this->actingAs($this->superadmin)->post(route('pengaturan.users.store'), [
            'name' => 'User Test Kasir',
            'email' => 'test-kasir@ksp.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'roles' => [999999], // non-existent role
            'is_active' => true,
        ]);

        $response->assertSessionHasErrors('roles.0');
        $this->assertDatabaseMissing('users', ['email' => 'test-kasir@ksp.test']);
    }

    public function test_president_director_role_is_active_and_has_approval_permissions(): void
    {
        $pd = Role::where('name', 'direktur_utama')->firstOrFail();
        $this->assertTrue($pd->is_active);
        $this->assertSame('President Director (Direktur Utama)', $pd->display_name);

        $pdUser = User::factory()->create(['is_active' => true]);
        $pdUser->roles()->attach($pd->id);

        $this->assertTrue($pdUser->hasPermission('shm.approval.decide'));
        $this->assertTrue($pdUser->hasPermission('shm.view'));
        $this->assertTrue($pdUser->hasPermission('shm.view_all'));
    }

    public function test_branch_manager_role_is_active_and_has_proper_permissions(): void
    {
        $bm = Role::where('name', 'branch_manager')->firstOrFail();
        $this->assertTrue($bm->is_active);
        $this->assertSame('Branch Manager', $bm->display_name);

        $bmUser = User::factory()->create(['is_active' => true]);
        $bmUser->roles()->attach($bm->id);

        // Permissions audit matrix for BM:
        $this->assertTrue($bmUser->hasPermission('dashboard.view'));
        $this->assertTrue($bmUser->hasPermission('anggota.view'));
        $this->assertTrue($bmUser->hasPermission('simpanan.view'));
        $this->assertTrue($bmUser->hasPermission('pinjaman.view'));
        $this->assertTrue($bmUser->hasPermission('angsuran.view'));
        $this->assertTrue($bmUser->hasPermission('kasir.view'));
        $this->assertTrue($bmUser->hasPermission('laporan.view'));
        $this->assertTrue($bmUser->hasPermission('laporan.export'));
        $this->assertTrue($bmUser->hasPermission('shm.view'));
        $this->assertTrue($bmUser->hasPermission('shm.view_all'));
        $this->assertTrue($bmUser->hasPermission('shm.collection.view'));

        // BM tidak boleh punya hak approval pinjaman tanpa konfirmasi
        $this->assertFalse($bmUser->hasPermission('pinjaman.approve'));
        $this->assertFalse($bmUser->hasPermission('shm.approval.decide'));
        // BM tidak boleh mengelola role & user
        $this->assertFalse($bmUser->hasPermission('role.create'));
        $this->assertFalse($bmUser->hasPermission('pengguna.create'));
    }

    public function test_superadmin_protection_cannot_be_deleted_or_reduced_to_zero(): void
    {
        $superadminRole = Role::where('name', 'superadmin')->firstOrFail();

        // Tidak boleh menghapus role superadmin
        $this->actingAs($this->superadmin)
            ->delete(route('pengaturan.roles.destroy', $superadminRole->id))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('roles', ['name' => 'superadmin']);

        // Tidak boleh menonaktifkan superadmin satu-satunya
        $this->actingAs($this->superadmin)
            ->patch(route('pengaturan.users.toggle-active', $this->superadmin->id))
            ->assertSessionHas('error');

        $this->assertTrue($this->superadmin->fresh()->is_active);

        // Tidak boleh mencabut role superadmin dari satu-satunya superadmin
        $this->actingAs($this->superadmin)
            ->patch(route('pengaturan.users.update-roles', $this->superadmin->id), [
                'roles' => [],
            ])
            ->assertSessionHas('error');

        $this->assertTrue($this->superadmin->fresh()->isSuperadmin());
    }

    // =========================================================================
    // 2. USER CRUD & PASSWORD HANDLING
    // =========================================================================

    public function test_guest_cannot_access_user_crud(): void
    {
        $this->get(route('pengaturan.users.index'))->assertRedirect(route('login'));
        $this->get(route('pengaturan.users.create'))->assertRedirect(route('login'));
        $this->post(route('pengaturan.users.store'), [])->assertRedirect(route('login'));
    }

    public function test_authorized_user_can_view_user_list_with_filters_and_sorting(): void
    {
        $this->actingAs($this->admin)
            ->get(route('pengaturan.users.index', [
                'search' => 'Superadmin',
                'role' => 'superadmin',
                'status' => 'active',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Pengaturan/Users/Index')
                ->has('users.data')
                ->has('roles')
            );
    }

    public function test_authorized_user_can_create_user_with_hashed_password(): void
    {
        $bmRoleId = Role::where('name', 'branch_manager')->value('id');

        $response = $this->actingAs($this->superadmin)->post(route('pengaturan.users.store'), [
            'name' => 'Manager Baru',
            'email' => 'manager.baru@ksp.test',
            'password' => 'secretP@ssw0rd!',
            'password_confirmation' => 'secretP@ssw0rd!',
            'roles' => [$bmRoleId],
            'is_active' => true,
        ]);

        $user = User::where('email', 'manager.baru@ksp.test')->firstOrFail();
        $response->assertRedirect(route('pengaturan.users.show', $user->id));

        $this->assertSame('Manager Baru', $user->name);
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->hasRole('branch_manager'));
        $this->assertTrue(Hash::check('secretP@ssw0rd!', $user->password));

        // Audit log tercatat
        $this->assertTrue(
            AuditLog::where('action', 'user.created')
                ->where('object_id', $user->id)
                ->exists()
        );
    }

    public function test_create_user_validates_required_fields_and_unique_email(): void
    {
        $existing = User::factory()->create();

        $response = $this->actingAs($this->superadmin)->post(route('pengaturan.users.store'), [
            'name' => '',
            'email' => $existing->email,
            'password' => '123',
            'password_confirmation' => 'mismatch',
        ]);

        $response->assertSessionHasErrors(['name', 'email', 'password']);
    }

    public function test_authorized_user_can_view_user_detail_and_edit_form(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin)
            ->get(route('pengaturan.users.show', $user->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Pengaturan/Users/Show')
                ->where('user.id', $user->id)
            );

        $this->actingAs($this->admin)
            ->get(route('pengaturan.users.edit', $user->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Pengaturan/Users/Edit')
                ->where('user.id', $user->id)
                ->has('roles')
            );
    }

    public function test_user_update_with_optional_password_and_role_change(): void
    {
        $user = User::factory()->create([
            'name' => 'Nama Lama',
            'email' => 'lama@ksp.test',
            'password' => Hash::make('password_lama'),
        ]);

        $bmRoleId = Role::where('name', 'branch_manager')->value('id');

        // Update tanpa password (password lama tetap)
        $response = $this->actingAs($this->superadmin)->put(route('pengaturan.users.update', $user->id), [
            'name' => 'Nama Diperbarui',
            'email' => 'baru@ksp.test',
            'roles' => [$bmRoleId],
            'is_active' => true,
        ]);

        $response->assertRedirect(route('pengaturan.users.show', $user->id));
        $user->refresh();

        $this->assertSame('Nama Diperbarui', $user->name);
        $this->assertSame('baru@ksp.test', $user->email);
        $this->assertTrue(Hash::check('password_lama', $user->password));
        $this->assertTrue($user->hasRole('branch_manager'));

        // Update dengan password baru
        $this->actingAs($this->superadmin)->put(route('pengaturan.users.update', $user->id), [
            'name' => 'Nama Diperbarui',
            'email' => 'baru@ksp.test',
            'password' => 'password_baru_123',
            'password_confirmation' => 'password_baru_123',
            'roles' => [$bmRoleId],
            'is_active' => true,
        ]);

        $user->refresh();
        $this->assertTrue(Hash::check('password_baru_123', $user->password));
    }

    public function test_user_toggle_active_deactivates_and_activates_account(): void
    {
        $target = User::factory()->create(['is_active' => true]);

        // Nonaktifkan
        $this->actingAs($this->superadmin)
            ->patch(route('pengaturan.users.toggle-active', $target->id))
            ->assertSessionHas('success');

        $this->assertFalse($target->fresh()->is_active);

        // Aktifkan kembali
        $this->actingAs($this->superadmin)
            ->patch(route('pengaturan.users.toggle-active', $target->id))
            ->assertSessionHas('success');

        $this->assertTrue($target->fresh()->is_active);
    }

    public function test_user_cannot_deactivate_self(): void
    {
        $this->actingAs($this->superadmin)
            ->patch(route('pengaturan.users.toggle-active', $this->superadmin->id))
            ->assertSessionHas('error');

        $this->assertTrue($this->superadmin->fresh()->is_active);
    }

    // =========================================================================
    // 3. MODULE INDEPENDENCE (KASIR & AUDIT REMAIN FUNCTIONAL)
    // =========================================================================

    public function test_cashier_module_and_routes_still_work_with_permissions(): void
    {
        // Admin memegang permission kasir.* dan dapat membuka modul kasir
        $this->actingAs($this->admin)
            ->get(route('kasir.index'))
            ->assertOk();

        $this->actingAs($this->admin)
            ->get(route('kasir.sesi.index'))
            ->assertOk();
    }

    public function test_audit_trail_still_works_without_pengawas_role(): void
    {
        $this->actingAs($this->superadmin)
            ->get(route('pengaturan.audit.index'))
            ->assertOk();
    }
}
