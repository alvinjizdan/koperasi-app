<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsModuleTest extends TestCase
{
    use RefreshDatabase;

    protected User $superadmin;

    protected User $adminMakro;

    protected User $marketingMakro;

    protected User $bmMakro;

    protected User $duMakro;

    protected User $financeMakro;

    protected User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup roles & permissions
        $permissions = [
            'shm.view',
            'pengaturan.view',
            'pengaturan.update',
            'pengguna.view',
            'pengguna.create',
            'pengguna.update',
            'pengguna.deactivate',
            'pengguna.reset_password',
            'pengguna.assign_role',
            'role.view',
            'role.create',
            'role.update',
            'role.delete',
            'audit.view',
        ];

        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p], [
                'display_name' => $p,
                'module' => 'settings',
                'action' => 'access',
            ]);
        }

        $superadminRole = Role::firstOrCreate(['name' => 'superadmin'], ['display_name' => 'Superadmin']);
        $adminMakroRole = Role::firstOrCreate(['name' => 'admin_makro'], ['display_name' => 'Admin Makro']);
        $marketingRole = Role::firstOrCreate(['name' => 'marketing_makro'], ['display_name' => 'Marketing Makro']);
        $bmRole = Role::firstOrCreate(['name' => 'branch_manager_makro'], ['display_name' => 'Branch Manager Makro']);
        $duRole = Role::firstOrCreate(['name' => 'direktur_utama_makro'], ['display_name' => 'Direktur Utama Makro']);
        $financeRole = Role::firstOrCreate(['name' => 'finance_operational_makro'], ['display_name' => 'Finance Operational Makro']);

        // Give permissions to Admin Makro
        $allPermissions = Permission::all();
        $superadminRole->permissions()->sync($allPermissions);
        $adminMakroRole->permissions()->sync($allPermissions);

        // Makro operational roles have shm.view
        $shmView = Permission::where('name', 'shm.view')->first();
        $marketingRole->permissions()->sync([$shmView->id]);
        $bmRole->permissions()->sync([$shmView->id]);
        $duRole->permissions()->sync([$shmView->id]);
        $financeRole->permissions()->sync([$shmView->id]);

        $this->superadmin = User::factory()->create();
        $this->superadmin->roles()->attach($superadminRole);

        $this->adminMakro = User::factory()->create();
        $this->adminMakro->roles()->attach($adminMakroRole);

        $this->marketingMakro = User::factory()->create();
        $this->marketingMakro->roles()->attach($marketingRole);

        $this->bmMakro = User::factory()->create();
        $this->bmMakro->roles()->attach($bmRole);

        $this->duMakro = User::factory()->create();
        $this->duMakro->roles()->attach($duRole);

        $this->financeMakro = User::factory()->create();
        $this->financeMakro->roles()->attach($financeRole);

        $this->regularUser = User::factory()->create();
    }

    public function test_superadmin_and_admin_makro_can_access_pengaturan_index(): void
    {
        $this->actingAs($this->superadmin)
            ->get(route('pengaturan.index'))
            ->assertOk();

        $this->actingAs($this->adminMakro)
            ->get(route('pengaturan.index'))
            ->assertOk();
    }

    public function test_pengaturan_users_index_renders_without_errors(): void
    {
        $this->actingAs($this->adminMakro)
            ->get(route('pengaturan.users.index'))
            ->assertOk();
    }

    public function test_pengaturan_users_create_renders(): void
    {
        $this->actingAs($this->adminMakro)
            ->get(route('pengaturan.users.create'))
            ->assertOk();
    }

    public function test_pengaturan_users_edit_renders(): void
    {
        $targetUser = User::factory()->create();

        $this->actingAs($this->adminMakro)
            ->get(route('pengaturan.users.edit', $targetUser->id))
            ->assertOk();
    }

    public function test_pengaturan_users_show_renders(): void
    {
        $targetUser = User::factory()->create();

        $this->actingAs($this->adminMakro)
            ->get(route('pengaturan.users.show', $targetUser->id))
            ->assertOk();
    }

    public function test_pengaturan_roles_index_renders(): void
    {
        $this->actingAs($this->adminMakro)
            ->get(route('pengaturan.roles.index'))
            ->assertOk();
    }

    public function test_pengaturan_roles_create_renders(): void
    {
        $this->actingAs($this->adminMakro)
            ->get(route('pengaturan.roles.create'))
            ->assertOk();
    }

    public function test_pengaturan_roles_permissions_renders(): void
    {
        $this->actingAs($this->adminMakro)
            ->get(route('pengaturan.roles.permissions'))
            ->assertOk();
    }

    public function test_pengaturan_roles_users_renders_authorized(): void
    {
        $targetRole = Role::firstOrCreate(['name' => 'petugas_lapangan'], [
            'display_name' => 'Petugas Lapangan',
            'entity' => 'shm',
            'is_active' => true,
        ]);

        $assignedUser = User::factory()->create(['name' => 'User Assigned To Role']);
        $assignedUser->roles()->attach($targetRole);

        $response = $this->actingAs($this->adminMakro)
            ->get(route('pengaturan.roles.users', $targetRole->id));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Pengaturan/Roles/Users')
            ->has('role', fn ($role) => $role
                ->where('id', $targetRole->id)
                ->where('name', 'petugas_lapangan')
                ->where('display_name', 'Petugas Lapangan')
                ->etc()
            )
            ->has('users.data', 1)
            ->where('users.data.0.id', $assignedUser->id)
            ->where('users.data.0.name', 'User Assigned To Role')
        );
    }

    public function test_pengaturan_profile_renders(): void
    {
        $this->actingAs($this->adminMakro)
            ->get(route('pengaturan.profile'))
            ->assertOk();
    }

    public function test_pengaturan_operational_renders(): void
    {
        $this->actingAs($this->adminMakro)
            ->get(route('pengaturan.operational'))
            ->assertOk();
    }

    public function test_pengaturan_security_renders(): void
    {
        $this->actingAs($this->adminMakro)
            ->get(route('pengaturan.security'))
            ->assertOk();
    }

    public function test_pengaturan_audit_index_renders(): void
    {
        $this->actingAs($this->adminMakro)
            ->get(route('pengaturan.audit.index'))
            ->assertOk();
    }

    public function test_pengaturan_audit_show_renders_authorized(): void
    {
        $log = AuditLog::query()->create([
            'actor_id' => $this->adminMakro->id,
            'action' => 'shm.loan.application.created',
            'object_type' => 'App\\Models\\ShmLoanApplication',
            'object_id' => 101,
            'object_label' => 'SHM-202611-001',
            'description' => 'Pengajuan pinjaman SHM dibuat.',
            'changes' => ['amount' => 50000000],
            'ip_address' => '127.0.0.1',
        ]);

        $response = $this->actingAs($this->adminMakro)
            ->get(route('pengaturan.audit.show', $log->id));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Pengaturan/AuditLogDetail')
            ->has('log', fn ($propLog) => $propLog
                ->where('id', $log->id)
                ->where('action', 'shm.loan.application.created')
                ->where('object_type', 'App\\Models\\ShmLoanApplication')
                ->where('object_id', 101)
                ->where('object_label', 'SHM-202611-001')
                ->where('description', 'Pengajuan pinjaman SHM dibuat.')
                ->etc()
            )
        );
    }

    public function test_unauthorized_users_cannot_access_pengaturan_users_index(): void
    {
        $this->actingAs($this->marketingMakro)
            ->get(route('pengaturan.users.index'))
            ->assertForbidden();

        $this->actingAs($this->regularUser)
            ->get(route('pengaturan.users.index'))
            ->assertForbidden();
    }

    public function test_unauthorized_users_cannot_access_pengaturan_roles_index(): void
    {
        $this->actingAs($this->bmMakro)
            ->get(route('pengaturan.roles.index'))
            ->assertForbidden();

        $this->actingAs($this->duMakro)
            ->get(route('pengaturan.roles.index'))
            ->assertForbidden();

        $this->actingAs($this->financeMakro)
            ->get(route('pengaturan.roles.index'))
            ->assertForbidden();
    }
}
