<?php

namespace Tests\Feature;

use App\Enums\EntityContext;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\MakroPhysicalRoleSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class PhysicalRoleSeparationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(MakroPhysicalRoleSeeder::class);
    }

    public function test_admin_mikro_cannot_access_makro_operations(): void
    {
        $role = Role::firstOrCreate(
            ['name' => 'admin_mikro', 'entity' => 'mikro'],
            ['display_name' => 'Admin Mikro', 'is_system' => true, 'is_active' => true]
        );
        $role->permissions()->sync(Permission::where('name', 'anggota.view')->pluck('id'));

        $user = User::factory()->create();
        $user->roles()->attach($role);

        // Within Mikro context: anggota.view is granted, makro permissions are denied
        app()->instance(EntityContext::class, EntityContext::Mikro);
        $this->assertTrue($user->hasPermission('anggota.view'));
        $this->assertTrue(Gate::forUser($user)->allows('anggota.view'));
        $this->assertFalse(Gate::forUser($user)->allows('shm.view'));
        $this->assertFalse(Gate::forUser($user)->allows('shm.approval.decide'));

        // Within Makro context: Admin Mikro has NO makro permissions
        app()->instance(EntityContext::class, EntityContext::Makro);
        $this->assertFalse($user->hasPermission('shm.view'));
        $this->assertFalse(Gate::forUser($user)->allows('shm.view'));
        $this->assertFalse($user->hasPermission('shm.approval.decide'));
        $this->assertFalse(Gate::forUser($user)->allows('shm.approval.decide'));
    }

    public function test_marketing_makro_least_privilege_isolation(): void
    {
        $role = Role::where('name', 'marketing_makro')->where('entity', 'makro')->firstOrFail();

        $user = User::factory()->create();
        $user->roles()->attach($role);

        app()->instance(EntityContext::class, EntityContext::Makro);
        $this->assertTrue(Gate::forUser($user)->allows('shm.view'));
        $this->assertTrue(Gate::forUser($user)->allows('shm.create'));
        $this->assertTrue(Gate::forUser($user)->allows('shm.credit_checking.update'));
        // Cannot do final approval, disbursement, or accounting
        $this->assertFalse(Gate::forUser($user)->allows('shm.approval.decide'));
        $this->assertFalse(Gate::forUser($user)->allows('shm.disburse'));
        $this->assertFalse(Gate::forUser($user)->allows('accounting.shm.view'));
    }

    public function test_user_with_both_mikro_and_makro_roles_does_not_leak_cross_entity(): void
    {
        $roleMikro = Role::firstOrCreate(
            ['name' => 'bm_mikro', 'entity' => 'mikro'],
            ['display_name' => 'BM Mikro', 'is_system' => true, 'is_active' => true]
        );
        $roleMikro->permissions()->sync(Permission::where('name', 'pinjaman.view')->pluck('id'));

        $roleMakro = Role::where('name', 'branch_manager_makro')->where('entity', 'makro')->firstOrFail();

        $user = User::factory()->create();
        $user->roles()->attach([$roleMikro->id, $roleMakro->id]);

        // In Mikro context: mikro permission granted, makro approval prohibited for BM
        app()->instance(EntityContext::class, EntityContext::Mikro);
        $this->assertTrue($user->hasPermission('pinjaman.view'));
        $this->assertTrue(Gate::forUser($user)->allows('pinjaman.view'));
        $this->assertFalse($user->hasPermission('shm.approval.decide'));
        $this->assertFalse(Gate::forUser($user)->allows('shm.approval.decide'));

        // In Makro context: makro permissions granted according to branch_manager_makro role
        app()->instance(EntityContext::class, EntityContext::Makro);
        $this->assertTrue($user->hasPermission('shm.view_all'));
        $this->assertTrue(Gate::forUser($user)->allows('shm.view_all'));
        $this->assertTrue(Gate::forUser($user)->allows('shm.collection.view'));
        $this->assertFalse($user->hasPermission('shm.approval.decide'));
        $this->assertFalse(Gate::forUser($user)->allows('shm.approval.decide'));
    }

    public function test_platform_admin_cannot_access_operational_accounting(): void
    {
        $role = Role::firstOrCreate(
            ['name' => 'platform_admin', 'entity' => 'shared'],
            ['display_name' => 'Platform Admin', 'is_system' => true, 'is_active' => true]
        );
        $role->permissions()->sync(Permission::whereIn('name', ['pengguna.view', 'role.view'])->pluck('id'));

        $user = User::factory()->create();
        $user->roles()->attach($role);

        // In Shared context
        app()->instance(EntityContext::class, EntityContext::Shared);
        $this->assertTrue(Gate::forUser($user)->allows('pengguna.view'));
        $this->assertTrue(Gate::forUser($user)->allows('role.view'));
        $this->assertFalse(Gate::forUser($user)->allows('accounting.view'));
        $this->assertFalse(Gate::forUser($user)->allows('accounting.shm.view'));
    }
}
