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

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        // Registry permission disemai mengikuti config/permissions.php.
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->superadmin = User::factory()->create();
        $this->superadmin->roles()->attach(Role::query()->where('name', 'superadmin')->value('id'));
    }

    /**
     * Pengguna biasa (nonaktif default tanpa role).
     */
    private function plainUser(bool $isActive = true): User
    {
        return User::factory()->create(['is_active' => $isActive]);
    }

    /**
     * Pengguna dengan satu permission tertentu.
     */
    private function userWithPermission(string $permission): User
    {
        $user = User::factory()->create();
        $role = Role::query()->create([
            'name' => 'custom_'.uniqid(),
            'display_name' => 'Custom',
            'is_system' => false,
        ]);
        $role->permissions()->attach(Permission::query()->where('name', $permission)->value('id'));
        $user->roles()->attach($role->id);

        return $user;
    }

    /** 1. Pengguna berwenang dapat melihat daftar pengguna */
    public function test_authorized_user_can_view_user_list(): void
    {
        $response = $this->actingAs($this->superadmin)->get(route('pengaturan.users.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Pengaturan/Users/Index')
            ->has('users')
            ->has('roles')
        );
    }

    /** 2. Pengguna berwenang dapat membuat dan mengubah akun */
    public function test_authorized_user_can_create_and_update_accounts(): void
    {
        $createData = [
            'name' => 'Petugas Baru',
            'email' => 'petugas@ksp.test',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
            'roles' => [Role::query()->where('name', 'kasir')->value('id')],
            'is_active' => true,
        ];

        $response = $this->actingAs($this->superadmin)->post(route('pengaturan.users.store'), $createData);

        $user = User::query()->where('email', 'petugas@ksp.test')->firstOrFail();
        $response->assertRedirect(route('pengaturan.users.show', $user->id));
        $this->assertTrue($user->hasRole('kasir'));

        $update = $this->actingAs($this->superadmin)->put(route('pengaturan.users.update', $user->id), [
            'name' => 'Petugas Diubah',
            'email' => 'petugas@ksp.test',
        ]);

        $update->assertRedirect(route('pengaturan.users.show', $user->id));
        $this->assertSame('Petugas Diubah', $user->fresh()->name);
    }

    /** 3. Email duplikat ditolak */
    public function test_duplicate_email_is_rejected(): void
    {
        $existing = User::factory()->create();

        $response = $this->actingAs($this->superadmin)->post(route('pengaturan.users.store'), [
            'name' => 'Duplikat',
            'email' => $existing->email,
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
        ]);

        $response->assertSessionHasErrors(['email']);
    }

    /** 4. Akun nonaktif tidak dapat login */
    public function test_inactive_account_cannot_login(): void
    {
        $user = $this->plainUser();
        $user->update(['is_active' => false]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();
    }

    /** 4b. Sesi berjalan dicabut saat akun dinonaktifkan */
    public function test_active_session_is_terminated_when_account_is_deactivated(): void
    {
        $user = $this->plainUser();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();

        $user->update(['is_active' => false]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('login'));
    }

    /** 5. Password disimpan menggunakan hashing yang benar */
    public function test_password_is_hashed(): void
    {
        $this->actingAs($this->superadmin)->post(route('pengaturan.users.store'), [
            'name' => 'Hash Test',
            'email' => 'hash@ksp.test',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
        ]);

        $user = User::query()->where('email', 'hash@ksp.test')->firstOrFail();

        $this->assertNotSame('rahasia123', $user->password);
        $this->assertTrue(Hash::check('rahasia123', $user->password));
    }

    /** 6. Pengguna tanpa permission tidak dapat mengakses route sensitif */
    public function test_user_without_permission_cannot_access_sensitive_routes(): void
    {
        $plain = $this->plainUser();

        $this->actingAs($plain)
            ->get(route('pengaturan.users.index'))
            ->assertForbidden();

        $this->actingAs($plain)
            ->get(route('pengaturan.roles.index'))
            ->assertForbidden();

        $this->actingAs($plain)
            ->get(route('pengaturan.audit.index'))
            ->assertForbidden();

        $other = $this->plainUser();

        $this->actingAs($plain)
            ->patch(route('pengaturan.users.toggle-active', $other->id))
            ->assertForbidden();
    }

    /** 6b. Pengguna dengan permission terbatas dapat mengakses modulnya tetapi bukan modul lain */
    public function test_limited_permission_user_can_only_access_their_module(): void
    {
        $limited = $this->userWithPermission('audit.view');

        $this->actingAs($limited)
            ->get(route('pengaturan.audit.index'))
            ->assertOk();

        $this->actingAs($limited)
            ->get(route('pengaturan.users.index'))
            ->assertForbidden();
    }

    /** 7. Pengguna tidak dapat meningkatkan permission dirinya sendiri */
    public function test_user_cannot_elevate_own_permissions(): void
    {
        $manager = $this->userWithPermission('pengguna.assign_role');

        $response = $this->actingAs($manager)
            ->patch(route('pengaturan.users.update-roles', $manager->id), [
                'roles' => [Role::query()->where('name', 'superadmin')->value('id')],
            ]);

        $response->assertSessionHas('error');
        $this->assertFalse($manager->fresh()->isSuperadmin());
        $this->assertSame([$manager->roles->first()->id], $manager->fresh()->roles()->pluck('roles.id')->all());
    }

    /** 7b. Pengguna tidak dapat menonaktifkan akun dirinya sendiri */
    public function test_user_cannot_deactivate_own_account_from_management(): void
    {
        $manager = $this->userWithPermission('pengguna.deactivate');

        $response = $this->actingAs($manager)
            ->patch(route('pengaturan.users.toggle-active', $manager->id));

        $response->assertSessionHas('error');
        $this->assertTrue($manager->fresh()->is_active);
    }

    /** 8. Perubahan role dan permission tersimpan dengan benar */
    public function test_role_and_permission_changes_persist(): void
    {
        $target = $this->plainUser();
        $kasirRole = Role::query()->where('name', 'kasir')->value('id');
        $marketingRole = Role::query()->where('name', 'marketing')->value('id');

        $this->actingAs($this->superadmin)
            ->patch(route('pengaturan.users.update-roles', $target->id), [
                'roles' => [$kasirRole, $marketingRole],
            ])
            ->assertSessionHas('success');

        $this->assertSame(
            [$kasirRole, $marketingRole],
            $target->fresh()->roles()->pluck('roles.id')->sort()->values()->all()
        );

        // Perubahan permission role tersimpan.
        $role = Role::query()->where('name', 'kasir')->first();
        $oldPermissions = $role->permissions()->pluck('permissions.name')->all();

        $this->actingAs($this->superadmin)
            ->put(route('pengaturan.roles.update', $role->id), [
                'display_name' => 'Kasir',
                'description' => $role->description,
                'permissions' => ['dashboard.view', 'kasir.view'],
            ])
            ->assertSessionHas('success');

        $this->assertSame(['dashboard.view', 'kasir.view'], $role->fresh()->permissions()->pluck('permissions.name')->all());

        // Kembalikan agar test lain tidak terpengaruh (meski RefreshDatabase menangani).
        $role->permissions()->sync(Permission::query()->whereIn('name', $oldPermissions)->pluck('id'));
    }

    /** 9. Role yang masih digunakan tidak dapat dihapus */
    public function test_role_in_use_cannot_be_deleted(): void
    {
        $target = $this->plainUser();
        $kasirRole = Role::query()->where('name', 'kasir')->first();

        $target->roles()->attach($kasirRole->id);

        $this->actingAs($this->superadmin)
            ->delete(route('pengaturan.roles.destroy', $kasirRole->id))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('roles', ['id' => $kasirRole->id]);
    }

    /** 9b. Role sistem dan Superadmin tidak dapat dihapus */
    public function test_system_role_cannot_be_deleted(): void
    {
        $superadminRole = Role::query()->where('name', 'superadmin')->first();

        $this->actingAs($this->superadmin)
            ->delete(route('pengaturan.roles.destroy', $superadminRole->id))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('roles', ['id' => $superadminRole->id]);
    }

    /** 10. Administrator terakhir tidak dapat kehilangan akses administratif */
    public function test_last_active_superadmin_is_protected(): void
    {
        // Satu-satunya superadmin aktif adalah $this->superadmin.
        $this->actingAs($this->superadmin)
            ->patch(route('pengaturan.users.toggle-active', $this->superadmin->id))
            ->assertSessionHas('error');

        $this->assertTrue($this->superadmin->fresh()->is_active);

        // Melepas role Superadmin juga ditolak.
        $this->actingAs($this->superadmin)
            ->patch(route('pengaturan.users.update-roles', $this->superadmin->id), [
                'roles' => [],
            ])
            ->assertSessionHas('error');

        $this->assertTrue($this->superadmin->fresh()->isSuperadmin());
    }

    /** 10b. Superadmin kedua boleh dinonaktifkan */
    public function test_second_superadmin_can_be_deactivated(): void
    {
        $second = User::factory()->create();
        $second->roles()->attach(Role::query()->where('name', 'superadmin')->value('id'));

        $this->actingAs($this->superadmin)
            ->patch(route('pengaturan.users.toggle-active', $second->id))
            ->assertSessionHas('success');

        $this->assertFalse($second->fresh()->is_active);
    }

    /** 11. Pengaturan profil koperasi dapat diperbarui oleh pengguna berwenang */
    public function test_settings_can_be_updated_by_authorized_user(): void
    {
        $settingsUser = $this->userWithPermission('pengaturan.update');

        $response = $this->actingAs($settingsUser)->put(route('pengaturan.profile.update'), [
            'coop_name' => 'KSP Bima Sakti Mandiri Baru',
            'coop_address' => 'Alamat Baru',
            'coop_phone' => '0231-123456',
            'coop_email' => 'info@ksp.test',
            'coop_legal_number' => 'AHU-0012817.AH.01.26.TAHUN.2021',
            'coop_tagline' => 'Melayani Anggota',
        ]);

        $response->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertDatabaseHas('settings', ['key' => 'coop_name', 'value' => 'KSP Bima Sakti Mandiri Baru']);
        $this->assertDatabaseHas('settings', ['key' => 'coop_phone', 'value' => '0231-123456']);
    }

    /** 11b. Halaman pengaturan menampilkan nilai tersimpan */
    public function test_settings_page_shows_saved_values(): void
    {
        // Ticket 17 BUG-01: halaman pengaturan juga memerlukan pengaturan.view.
        $settingsUser = $this->userWithPermission('pengaturan.update');
        $viewRole = Role::query()->create([
            'name' => 'custom_view_'.uniqid(),
            'display_name' => 'Custom View',
            'is_system' => false,
        ]);
        $viewRole->permissions()->attach(Permission::query()->where('name', 'pengaturan.view')->value('id'));
        $settingsUser->roles()->attach($viewRole->id);

        $this->actingAs($settingsUser)->put(route('pengaturan.profile.update'), [
            'coop_name' => 'KSP Ubah Nama',
            'coop_address' => 'Alamat',
        ]);

        $response = $this->actingAs($settingsUser)->get(route('pengaturan.profile'));

        $response->assertInertia(fn ($page) => $page
            ->where('settings.coop_name', 'KSP Ubah Nama')
        );
    }

    /** 12. Pengguna tanpa permission tidak dapat mengubah pengaturan */
    public function test_user_without_permission_cannot_update_settings(): void
    {
        $plain = $this->plainUser();

        $this->actingAs($plain)
            ->put(route('pengaturan.profile.update'), [
                'coop_name' => 'Diubah Tanpa Izin',
                'coop_address' => 'Alamat',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('settings', ['key' => 'coop_name', 'value' => 'Diubah Tanpa Izin']);
    }

    /** 13. Perubahan penting tercatat dalam audit trail */
    public function test_important_changes_are_recorded_in_audit_trail(): void
    {
        $target = $this->plainUser();

        $this->actingAs($this->superadmin)->post(route('pengaturan.users.store'), [
            'name' => 'Audit User',
            'email' => 'audit@ksp.test',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
        ]);

        $created = User::query()->where('email', 'audit@ksp.test')->firstOrFail();

        $this->actingAs($this->superadmin)->patch(route('pengaturan.users.toggle-active', $created->id));

        $this->actingAs($this->superadmin)->patch(route('pengaturan.users.update-roles', $created->id), [
            'roles' => [Role::query()->where('name', 'kasir')->value('id')],
        ]);

        $this->actingAs($this->superadmin)->put(route('pengaturan.profile.update'), [
            'coop_name' => 'KSP Audit',
            'coop_address' => 'Alamat',
        ]);

        $actions = AuditLog::query()->whereIn('object_type', ['User', 'Role', 'Setting'])->pluck('action')->all();

        $this->assertContains('user.created', $actions);
        $this->assertContains('user.deactivated', $actions);
        $this->assertContains('user.roles_changed', $actions);
        $this->assertContains('settings.updated', $actions);

        // Audit log mencatat aktor.
        $this->assertTrue(AuditLog::query()->where('action', 'user.created')->where('actor_id', $this->superadmin->id)->exists());
    }

    /** 13b. Password tidak pernah masuk audit log */
    public function test_password_is_never_written_to_audit_log(): void
    {
        $this->actingAs($this->superadmin)->post(route('pengaturan.users.store'), [
            'name' => 'Secret User',
            'email' => 'secret@ksp.test',
            'password' => 'RAHASIA_SUPER_TEBAL_2026',
            'password_confirmation' => 'RAHASIA_SUPER_TEBAL_2026',
        ]);

        $logs = AuditLog::query()->get();

        foreach ($logs as $log) {
            $this->assertStringNotContainsString('RAHASIA_SUPER_TEBAL_2026', (string) json_encode($log->changes));
            $this->assertStringNotContainsString('RAHASIA_SUPER_TEBAL_2026', (string) $log->description);
        }
    }

    /** 14. Perubahan role tidak merusak akses modul yang sudah ada */
    public function test_role_change_preserves_module_access_for_other_users(): void
    {
        $kasir = User::factory()->create();
        $kasir->roles()->attach(Role::query()->where('name', 'kasir')->value('id'));

        $other = User::factory()->create();
        // Role admin memegang seluruh permission (all_permissions), termasuk
        // pinjaman.approve, sehingga cocok untuk membuktikan bahwa akses user
        // lain tidak ikut berubah.
        $other->roles()->attach(Role::query()->where('name', 'admin')->value('id'));

        // Kasir dapat membuka sesi (kasir.open_session).
        $this->assertTrue($kasir->fresh()->hasPermission('kasir.open_session'));
        $this->assertTrue($other->fresh()->hasPermission('pinjaman.approve'));
        $this->assertFalse($kasir->fresh()->hasPermission('pinjaman.approve'));

        // Perubahan role kasir (role lain ditambahkan) tidak menghapus akses kasir.
        $this->actingAs($this->superadmin)->patch(route('pengaturan.users.update-roles', $kasir->id), [
            'roles' => [
                Role::query()->where('name', 'kasir')->value('id'),
                Role::query()->where('name', 'marketing')->value('id'),
            ],
        ]);

        $this->assertTrue($kasir->fresh()->hasPermission('kasir.open_session'));
        // Akses bawaan kasir tetap utuh.
        $this->assertTrue($kasir->fresh()->hasPermission('simpanan.deposit'));
        // Role marketing menambah hak baca tanpa menghapus hak lama.
        $this->assertTrue($kasir->fresh()->hasPermission('pinjaman.view'));
    }

    /** 14b. Akses modul laporan tetap berfungsi setelah sistem permission diaktifkan */
    public function test_existing_module_access_works_with_permission_system(): void
    {
        // Superadmin dapat mengakses seluruh modul yang sudah ada.
        $this->actingAs($this->superadmin)
            ->get(route('laporan.index'))
            ->assertOk();

        // Pengguna dengan permission laporan.view dapat mengakses laporan.
        $viewer = $this->userWithPermission('laporan.view');
        $this->actingAs($viewer)
            ->get(route('laporan.index'))
            ->assertOk();

        // Pengguna tanpa permission laporan ditolak.
        $plain = $this->plainUser();
        $this->actingAs($plain)
            ->get(route('laporan.index'))
            ->assertForbidden();
    }

    /** 15. Proteksi akun nonaktif berjalan dengan benar */
    public function test_inactive_account_protection(): void
    {
        $inactive = User::factory()->create(['is_active' => false]);

        // Tidak dapat login.
        $this->post('/login', [
            'email' => $inactive->email,
            'password' => 'password',
        ]);
        $this->assertGuest();

        // Sesi yang sudah ada tetap ditolak.
        $this->actingAs($inactive)
            ->get(route('dashboard'))
            ->assertRedirect(route('login'));
    }

    /** Reset password administrator menyimpan hash dan tidak membocorkan nilai */
    public function test_admin_password_reset_works(): void
    {
        $target = $this->plainUser();

        $this->actingAs($this->superadmin)
            ->patch(route('pengaturan.users.reset-password', $target->id), [
                'password' => 'password-baru-2026',
                'password_confirmation' => 'password-baru-2026',
            ])
            ->assertSessionHas('success');

        $this->assertTrue(Hash::check('password-baru-2026', $target->fresh()->password));
        $this->assertDatabaseMissing('audit_logs', [
            ['description', 'like', '%password-baru-2026%'],
        ]);
    }
}
