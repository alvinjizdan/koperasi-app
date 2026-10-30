<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionRegistry;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * T105: guard eskalasi privilege pada manajemen Role & User.
 *
 * Kontrak yang diuji:
 * - superadmin tetap full authority (Gate::before tidak berubah);
 * - actor non-superadmin dengan role.update tidak dapat mengubah role
 *   superadmin/role sistem/role miliknya sendiri, dan tidak dapat memberikan
 *   permission di luar authority-nya;
 * - actor non-superadmin dengan pengguna.* tidak dapat membuat/menugaskan role
 *   superadmin, mempromosikan diri sendiri, maupun melebihi authority-nya;
 * - akun superadmin hanya dikelola actor superadmin (role/status/password);
 * - percobaan yang gagal tidak mengubah database;
 * - architecture permission T101 (middleware, seeder, registry) tetap utuh.
 */
class PrivilegeEscalationTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->superadmin = User::factory()->create();
        $this->superadmin->roles()->attach($this->superadminRoleId());
    }

    private function superadminRoleId(): int
    {
        return (int) Role::query()->where('name', Role::SYSTEM_SUPERADMIN)->value('id');
    }

    /**
     * Pengguna dengan custom role berisi permission tertentu.
     *
     * @param  array<int, string>  $permissions
     */
    private function userWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $role = $this->customRole($permissions);
        $user->roles()->attach($role->id);

        return $user;
    }

    /**
     * Custom role (is_system=false) berisi permission yang diberikan.
     *
     * @param  array<int, string>  $permissions
     */
    private function customRole(array $permissions): Role
    {
        $role = Role::query()->create([
            'name' => 'custom_'.uniqid(),
            'display_name' => 'Custom',
            'description' => null,
            'is_system' => false,
            'is_active' => true,
        ]);

        $role->permissions()->sync(
            Permission::query()->whereIn('name', $permissions)->pluck('id')->all()
        );

        return $role;
    }

    // ================================================================
    // ROLE
    // ================================================================

    /** R1. Superadmin tetap dapat memperbarui role. */
    public function test_superadmin_can_update_role(): void
    {
        $role = $this->customRole(['dashboard.view']);

        $this->actingAs($this->superadmin)
            ->put(route('pengaturan.roles.update', $role->id), [
                'display_name' => 'Custom Baru',
                'description' => 'diubah',
                'permissions' => ['dashboard.view', 'laporan.view'],
            ])
            ->assertRedirect(route('pengaturan.roles.index'))
            ->assertSessionHas('success');

        $this->assertSame(
            ['dashboard.view', 'laporan.view'],
            $role->fresh()->permissions()->pluck('permissions.name')->sort()->values()->all()
        );
    }

    /** R2. Non-superadmin tidak dapat mengubah role Superadmin. */
    public function test_non_superadmin_cannot_update_superadmin_role(): void
    {
        $actor = $this->userWithPermissions(['role.update', 'dashboard.view']);
        $role = Role::query()->where('name', Role::SYSTEM_SUPERADMIN)->firstOrFail();
        $before = $role->display_name;

        $this->actingAs($actor)
            ->put(route('pengaturan.roles.update', $role->id), [
                'display_name' => 'Diedit Aktor',
                'permissions' => ['dashboard.view'],
            ])
            ->assertRedirect(route('pengaturan.roles.index'))
            ->assertSessionHas('error');

        $this->assertSame($before, $role->fresh()->display_name);
        // Role superadmin tidak punya permission eksplisit (murni bypass).
        $this->assertSame(0, $role->permissions()->count());
    }

    /** R3. Non-superadmin tidak dapat mengubah role sistem. */
    public function test_non_superadmin_cannot_update_system_role(): void
    {
        $actor = $this->userWithPermissions(['role.update', 'dashboard.view']);
        $role = Role::query()->where('name', 'branch_manager')->firstOrFail();
        $before = [
            'display_name' => $role->display_name,
            'permissions' => $role->permissions()->pluck('permissions.name')->sort()->values()->all(),
        ];

        $this->actingAs($actor)
            ->put(route('pengaturan.roles.update', $role->id), [
                'display_name' => 'Branch Manager Diubah',
                'permissions' => ['dashboard.view'],
            ])
            ->assertRedirect(route('pengaturan.roles.index'))
            ->assertSessionHas('error');

        $fresh = $role->fresh();
        $this->assertSame($before['display_name'], $fresh->display_name);
        $this->assertSame(
            $before['permissions'],
            $fresh->permissions()->pluck('permissions.name')->sort()->values()->all()
        );
    }

    /** R4. Actor tidak dapat mengubah role yang sedang ia gunakan sendiri. */
    public function test_non_superadmin_cannot_edit_own_role(): void
    {
        $actor = $this->userWithPermissions(['role.update', 'laporan.view']);
        $ownRole = $actor->roles()->firstOrFail();
        $before = $ownRole->permissions()->pluck('permissions.name')->sort()->values()->all();

        $this->actingAs($actor)
            ->put(route('pengaturan.roles.update', $ownRole->id), [
                'display_name' => 'Custom',
                // Permission ini memang dipegang actor — yang menolak adalah
                // guard self-edit, bukan ceiling delegation.
                'permissions' => ['role.update', 'laporan.view', 'audit.view'],
            ])
            ->assertRedirect(route('pengaturan.roles.index'))
            ->assertSessionHas('error');

        $this->assertSame($before, $ownRole->fresh()->permissions()->pluck('permissions.name')->sort()->values()->all());
    }

    /** R5. Non-superadmin tidak dapat memberikan permission di luar authority-nya. */
    public function test_non_superadmin_cannot_grant_permissions_outside_their_authority(): void
    {
        $actor = $this->userWithPermissions(['role.update', 'dashboard.view']);
        $target = $this->customRole(['dashboard.view']);

        $this->actingAs($actor)
            ->put(route('pengaturan.roles.update', $target->id), [
                'display_name' => 'Target',
                'permissions' => ['dashboard.view', 'laporan.view'],
            ])
            ->assertRedirect(route('pengaturan.roles.index'))
            ->assertSessionHas('error');

        // Tidak ada perubahan sama sekali (bukan sekadar permission liar yang
        // difilter — seluruh update ditolak).
        $this->assertSame(['dashboard.view'], $target->fresh()->permissions()->pluck('permissions.name')->all());
    }

    /** R6. Delegasi legitimate tetap berfungsi: permission yang dipegang actor boleh diberikan. */
    public function test_non_superadmin_can_grant_permissions_they_hold(): void
    {
        $actor = $this->userWithPermissions(['role.update', 'dashboard.view', 'angsuran.view']);
        $target = $this->customRole(['dashboard.view']);

        $this->actingAs($actor)
            ->put(route('pengaturan.roles.update', $target->id), [
                'display_name' => 'Target',
                'permissions' => ['dashboard.view', 'angsuran.view'],
            ])
            ->assertSessionHas('success');

        $this->assertSame(
            ['angsuran.view', 'dashboard.view'],
            $target->fresh()->permissions()->pluck('permissions.name')->sort()->values()->all()
        );
    }

    /** R7. Non-superadmin tidak dapat membuat role dengan permission di luar authority. */
    public function test_non_superadmin_cannot_create_role_with_unheld_permissions(): void
    {
        $actor = $this->userWithPermissions(['role.create', 'dashboard.view']);

        $this->actingAs($actor)
            ->post(route('pengaturan.roles.store'), [
                'name' => 'escalated_role',
                'display_name' => 'Escalated',
                'permissions' => ['dashboard.view', 'laporan.view'],
            ])
            ->assertSessionHasErrors('permissions');

        $this->assertDatabaseMissing('roles', ['name' => 'escalated_role']);
    }

    /** R7b. Pembuatan role dalam authority tetap berfungsi. */
    public function test_non_superadmin_can_create_role_within_authority(): void
    {
        $actor = $this->userWithPermissions(['role.create', 'dashboard.view']);

        $this->actingAs($actor)
            ->post(route('pengaturan.roles.store'), [
                'name' => 'ops_custom',
                'display_name' => 'Ops Custom',
                'permissions' => ['dashboard.view'],
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('roles', ['name' => 'ops_custom']);
    }

    /** R8. Superadmin tetap dapat mengubah role sistem (bypass utuh). */
    public function test_superadmin_can_update_system_role(): void
    {
        $role = Role::query()->where('name', 'branch_manager')->firstOrFail();

        $this->actingAs($this->superadmin)
            ->put(route('pengaturan.roles.update', $role->id), [
                'display_name' => 'Branch Manager Utama',
                'permissions' => ['dashboard.view', 'laporan.view'],
            ])
            ->assertSessionHas('success');

        $this->assertSame('Branch Manager Utama', $role->fresh()->display_name);
    }

    // ================================================================
    // USER
    // ================================================================

    /** U1. Superadmin dapat membuat user dengan role legitimate. */
    public function test_superadmin_can_create_user_with_legitimate_role(): void
    {
        $bmId = (int) Role::query()->where('name', 'branch_manager')->value('id');

        $this->actingAs($this->superadmin)
            ->post(route('pengaturan.users.store'), [
                'name' => 'Petugas Baru',
                'email' => 'petugas.baru@ksp.test',
                'password' => 'rahasia123',
                'roles' => [$bmId],
            ])
            ->assertSessionHas('success');

        $created = User::query()->where('email', 'petugas.baru@ksp.test')->firstOrFail();
        $this->assertTrue($created->hasRole('branch_manager'));
    }

    /** U2. Non-superadmin tidak dapat membuat user dengan role superadmin. */
    public function test_non_superadmin_cannot_create_superadmin_user(): void
    {
        $actor = $this->userWithPermissions(['pengguna.create', 'dashboard.view']);

        $this->actingAs($actor)
            ->post(route('pengaturan.users.store'), [
                'name' => 'Puppet',
                'email' => 'puppet@ksp.test',
                'password' => 'rahasia123',
                'roles' => [$this->superadminRoleId()],
            ])
            ->assertSessionHasErrors('roles');

        $this->assertDatabaseMissing('users', ['email' => 'puppet@ksp.test']);
    }

    /** U3. Non-superadmin tidak dapat menugaskan role superadmin ke user lain. */
    public function test_non_superadmin_cannot_assign_superadmin_role(): void
    {
        $actor = $this->userWithPermissions(['pengguna.view', 'pengguna.assign_role', 'dashboard.view']);
        $target = User::factory()->create();

        $this->actingAs($actor)
            ->patch(route('pengaturan.users.update-roles', $target->id), [
                'roles' => [$this->superadminRoleId()],
            ])
            ->assertSessionHas('error');

        $this->assertFalse($target->fresh()->isSuperadmin());
        $this->assertCount(0, $target->fresh()->roles);

        // Halaman show tetap ter-render dan meneruskan flash guard ke UI.
        $this->get(route('pengaturan.users.show', $target->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Pengaturan/Users/Show')
                ->where('flash.error', 'Role Superadmin hanya dapat ditetapkan oleh Superadmin.'));
    }

    /** U4. Non-superadmin tidak dapat menugaskan role melebihi authority-nya. */
    public function test_non_superadmin_cannot_assign_role_beyond_own_authority(): void
    {
        $actor = $this->userWithPermissions(['pengguna.assign_role', 'dashboard.view']);
        $target = User::factory()->create();
        $bmId = (int) Role::query()->where('name', 'branch_manager')->value('id');

        // Role branch_manager memuat permission (laporan.*, anggota.view, dll.) yang tidak dipegang actor.
        $this->actingAs($actor)
            ->patch(route('pengaturan.users.update-roles', $target->id), [
                'roles' => [$bmId],
            ])
            ->assertSessionHas('error');

        $this->assertCount(0, $target->fresh()->roles);
    }

    /** U5. Actor tidak dapat mengubah role dirinya sendiri (promosi diri/self-lock). */
    public function test_actor_cannot_change_own_roles(): void
    {
        $actor = $this->userWithPermissions(['pengguna.assign_role', 'dashboard.view']);
        $ownRoleIds = $actor->roles()->pluck('roles.id')->all();

        // Promosi diri ke superadmin.
        $this->actingAs($actor)
            ->patch(route('pengaturan.users.update-roles', $actor->id), [
                'roles' => [$this->superadminRoleId()],
            ])
            ->assertSessionHas('error');

        // Mengganti role sendiri dengan role lain pun tetap ditolak (self-lock).
        $otherRole = $this->customRole(['dashboard.view']);
        $this->actingAs($actor)
            ->patch(route('pengaturan.users.update-roles', $actor->id), [
                'roles' => [$otherRole->id],
            ])
            ->assertSessionHas('error');

        $fresh = $actor->fresh();
        $this->assertFalse($fresh->isSuperadmin());
        $this->assertSame($ownRoleIds, $fresh->roles()->pluck('roles.id')->all());
    }

    /** U6. Akun Superadmin tidak dapat diubah oleh actor non-superadmin (role/status/password). */
    public function test_non_superadmin_cannot_modify_superadmin_account(): void
    {
        $actor = $this->userWithPermissions([
            'pengguna.assign_role',
            'pengguna.deactivate',
            'pengguna.reset_password',
        ]);

        $target = User::factory()->create();
        $target->roles()->attach($this->superadminRoleId());
        $bmId = (int) Role::query()->where('name', 'branch_manager')->value('id');

        // Perubahan role ditolak.
        $this->actingAs($actor)
            ->patch(route('pengaturan.users.update-roles', $target->id), ['roles' => [$bmId]])
            ->assertSessionHas('error');
        $this->assertTrue($target->fresh()->isSuperadmin());

        // Reset password ditolak — mencegah pengambilalihan akun superadmin.
        $this->actingAs($actor)
            ->patch(route('pengaturan.users.reset-password', $target->id), [
                'password' => 'password-baru-2026',
                'password_confirmation' => 'password-baru-2026',
            ])
            ->assertSessionHas('error');
        $this->assertTrue(Hash::check('password', $target->fresh()->password));

        // Penonaktifan ditolak.
        $this->actingAs($actor)
            ->patch(route('pengaturan.users.toggle-active', $target->id))
            ->assertSessionHas('error');
        $this->assertTrue($target->fresh()->is_active);
    }

    /** U7. Superadmin tetap dapat mempromosikan user lain menjadi superadmin. */
    public function test_superadmin_can_assign_superadmin_role_to_others(): void
    {
        $target = User::factory()->create();

        $this->actingAs($this->superadmin)
            ->patch(route('pengaturan.users.update-roles', $target->id), [
                'roles' => [$this->superadminRoleId()],
            ])
            ->assertSessionHas('success');

        $this->assertTrue($target->fresh()->isSuperadmin());
    }

    // ================================================================
    // SECURITY INVARIANTS
    // ================================================================

    /** I1. Serangkaian percobaan eskalasi gagal tidak mengubah database sama sekali. */
    public function test_failed_escalations_leave_database_unchanged(): void
    {
        $actor = $this->userWithPermissions([
            'role.update', 'role.create',
            'pengguna.create', 'pengguna.assign_role', 'pengguna.deactivate', 'pengguna.reset_password',
        ]);
        $systemRole = Role::query()->where('name', 'branch_manager')->firstOrFail();
        $superadminRole = Role::query()->where('name', Role::SYSTEM_SUPERADMIN)->firstOrFail();
        $victim = User::factory()->create();

        $snapshot = fn () => [
            'roles' => Role::query()->count(),
            'permissions' => Permission::query()->count(),
            'role_user' => DB::table('role_user')->count(),
            'permission_role' => DB::table('permission_role')->count(),
            'users' => User::query()->count(),
            'bm_permissions' => $systemRole->permissions()->pluck('permissions.name')->sort()->values()->all(),
            'actor_permissions' => $actor->fresh()->effectivePermissions()->pluck('name')->sort()->values()->all(),
        ];
        $before = $snapshot();

        // 1. Eskalasi role: edit role sendiri.
        $ownRole = $actor->roles()->firstOrFail();
        $this->actingAs($actor)
            ->put(route('pengaturan.roles.update', $ownRole->id), [
                'display_name' => 'Custom',
                'permissions' => Permission::query()->pluck('name')->all(),
            ])
            ->assertSessionHas('error');

        // 2. Eskalasi role: edit role sistem & role superadmin.
        $this->actingAs($actor)
            ->put(route('pengaturan.roles.update', $systemRole->id), [
                'display_name' => 'X',
                'permissions' => ['laporan.view'],
            ])
            ->assertSessionHas('error');
        $this->actingAs($actor)
            ->put(route('pengaturan.roles.update', $superadminRole->id), [
                'display_name' => 'X',
                'permissions' => ['dashboard.view'],
            ])
            ->assertSessionHas('error');

        // 3. Eskalasi user: buat puppet superadmin + promosikan user lain.
        $this->actingAs($actor)
            ->post(route('pengaturan.users.store'), [
                'name' => 'Puppet',
                'email' => 'puppet@ksp.test',
                'password' => 'rahasia123',
                'roles' => [$this->superadminRoleId()],
            ])
            ->assertSessionHasErrors('roles');
        $this->actingAs($actor)
            ->patch(route('pengaturan.users.update-roles', $victim->id), [
                'roles' => [$this->superadminRoleId()],
            ])
            ->assertSessionHas('error');

        // 4. Percobaan status/password pada akun superadmin.
        $this->actingAs($actor)
            ->patch(route('pengaturan.users.toggle-active', $this->superadmin->id))
            ->assertSessionHas('error');
        $this->actingAs($actor)
            ->patch(route('pengaturan.users.reset-password', $this->superadmin->id), [
                'password' => 'password-baru-2026',
                'password_confirmation' => 'password-baru-2026',
            ])
            ->assertSessionHas('error');

        $this->assertSame($before, $snapshot(), 'Percobaan eskalasi yang gagal tidak boleh mengubah database.');
        $this->assertFalse($actor->fresh()->isSuperadmin());
        $this->assertFalse($victim->fresh()->isSuperadmin());
        $this->assertTrue($this->superadmin->fresh()->is_active);
        $this->assertTrue(Hash::check('password', $this->superadmin->fresh()->password));
    }

    /** I2. Middleware permission T101 tetap menjadi enforcement layer. */
    public function test_permission_middleware_still_blocks_unauthorized_users(): void
    {
        $plain = User::factory()->create();
        $role = $this->customRole(['dashboard.view']);

        $this->actingAs($plain)
            ->put(route('pengaturan.roles.update', $role->id), [
                'display_name' => 'X',
                'permissions' => ['laporan.view'],
            ])
            ->assertForbidden();

        $this->actingAs($plain)
            ->post(route('pengaturan.users.store'), [
                'name' => 'X',
                'email' => 'x@ksp.test',
                'password' => 'rahasia123',
            ])
            ->assertForbidden();

        $this->assertSame(['dashboard.view'], $role->fresh()->permissions()->pluck('permissions.name')->all());
        $this->assertDatabaseMissing('users', ['email' => 'x@ksp.test']);
    }

    /** I3. Registry permission tidak terkorupsi setelah seluruh percobaan. */
    public function test_permission_registry_remains_intact(): void
    {
        $registered = app(PermissionRegistry::class)->allNames();
        sort($registered);

        $inDatabase = Permission::query()->pluck('name')->all();
        sort($inDatabase);

        $this->assertSame($registered, $inDatabase);
        // Role superadmin tidak pernah punya permission eksplisit.
        $superadminRole = Role::query()->where('name', Role::SYSTEM_SUPERADMIN)->firstOrFail();
        $this->assertSame(0, $superadminRole->permissions()->count());
        $this->assertTrue($superadminRole->is_system);
    }
}
