<?php

namespace Tests\Feature;

use App\Enums\EntityContext;
use App\Http\Middleware\ResolveEntityContext;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class EntityContextAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed roles & permissions minimal untuk pengujian isolasi konteks entitas
        Permission::query()->create(['name' => 'anggota.view', 'module' => 'anggota', 'action' => 'view', 'entity' => 'mikro', 'display_name' => 'View Anggota']);
        Permission::query()->create(['name' => 'kasir.view', 'module' => 'kasir', 'action' => 'view', 'entity' => 'mikro', 'display_name' => 'View Kasir']);
        Permission::query()->create(['name' => 'shm.view', 'module' => 'shm', 'action' => 'view', 'entity' => 'makro', 'display_name' => 'View SHM']);
        Permission::query()->create(['name' => 'shm.approval.decide', 'module' => 'shm', 'action' => 'approval.decide', 'entity' => 'makro', 'display_name' => 'Decide Approval SHM']);
        Permission::query()->create(['name' => 'dashboard.view', 'module' => 'dashboard', 'action' => 'view', 'entity' => 'shared', 'display_name' => 'View Dashboard']);
        Permission::query()->create(['name' => 'pengguna.view', 'module' => 'pengguna', 'action' => 'view', 'entity' => 'shared', 'display_name' => 'View Pengguna']);
    }

    public function test_mikro_context_enum_and_role_isolation(): void
    {
        // EntityContext::Mikro mengizinkan entity 'mikro' dan 'shared', tetapi menolak 'makro'
        $context = EntityContext::Mikro;
        $this->assertTrue($context->allowsPermissionEntity('mikro'));
        $this->assertTrue($context->allowsPermissionEntity('shared'));
        $this->assertFalse($context->allowsPermissionEntity('makro'));
        $this->assertEquals(['mikro', 'shared'], $context->allowedPermissionEntities());

        // Role Mikro hanya memegang permission mikro
        $roleMikro = Role::query()->create(['name' => 'staff_mikro', 'display_name' => 'Staff Mikro', 'entity' => 'mikro', 'is_active' => true]);
        $roleMikro->permissions()->sync(Permission::where('name', 'anggota.view')->pluck('id'));

        $user = User::factory()->create();
        $user->roles()->attach($roleMikro);

        $this->assertTrue($user->hasPermission('anggota.view'));
        $this->assertFalse($user->hasPermission('shm.view'));
    }

    public function test_makro_context_enum_and_role_isolation(): void
    {
        // EntityContext::Makro mengizinkan entity 'makro' dan 'shared', tetapi menolak 'mikro'
        $context = EntityContext::Makro;
        $this->assertTrue($context->allowsPermissionEntity('makro'));
        $this->assertTrue($context->allowsPermissionEntity('shared'));
        $this->assertFalse($context->allowsPermissionEntity('mikro'));
        $this->assertEquals(['makro', 'shared'], $context->allowedPermissionEntities());

        // Role Makro memegang permission makro
        $roleMakro = Role::query()->firstOrCreate(
            ['name' => 'marketing_makro', 'entity' => 'makro'],
            ['display_name' => 'Marketing Makro', 'is_active' => true]
        );
        $roleMakro->permissions()->sync(Permission::where('name', 'shm.view')->pluck('id'));

        $user = User::factory()->create();
        $user->roles()->attach($roleMakro);

        $this->assertTrue($user->hasPermission('shm.view'));
        $this->assertFalse($user->hasPermission('anggota.view'));
    }

    public function test_shared_permissions_are_accessible_in_mikro_and_makro_contexts(): void
    {
        $contextShared = EntityContext::Shared;
        $this->assertTrue($contextShared->allowsPermissionEntity('shared'));
        $this->assertFalse($contextShared->allowsPermissionEntity('mikro'));
        $this->assertFalse($contextShared->allowsPermissionEntity('makro'));

        $roleMikro = Role::query()->create(['name' => 'admin_mikro', 'display_name' => 'Admin Mikro', 'entity' => 'mikro', 'is_active' => true]);
        $roleMikro->permissions()->sync(Permission::whereIn('name', ['anggota.view', 'dashboard.view'])->pluck('id'));

        $user = User::factory()->create();
        $user->roles()->attach($roleMikro);

        $this->assertTrue($user->hasPermission('dashboard.view'));
        $this->assertTrue($user->hasPermission('anggota.view'));
        $this->assertFalse($user->hasPermission('shm.view'));
    }

    public function test_inactive_roles_contribute_permissions_per_legacy_policy(): void
    {
        $roleInactive = Role::query()->create(['name' => 'kasir_inactive', 'display_name' => 'Kasir Inactive', 'entity' => 'mikro', 'is_active' => false]);
        $roleInactive->permissions()->sync(Permission::where('name', 'kasir.view')->pluck('id'));

        $user = User::factory()->create();
        $user->roles()->attach($roleInactive);

        // Menonaktifkan role tidak mencabut akses pengguna yang masih memakainya
        $this->assertNotEmpty($user->effectivePermissions());
        $this->assertTrue($user->hasPermission('kasir.view'));
    }

    public function test_superadmin_has_full_permission_access(): void
    {
        $superadminRole = Role::query()->firstOrCreate(
            ['name' => Role::SYSTEM_SUPERADMIN],
            ['display_name' => 'Superadmin', 'entity' => 'shared', 'is_active' => true, 'is_system' => true]
        );
        $superadmin = User::factory()->create();
        $superadmin->roles()->attach($superadminRole);

        // Superadmin memiliki akses ke semua permission melalui isSuperadmin
        $this->assertTrue($superadmin->isSuperadmin());
        $this->assertTrue($superadmin->hasPermission('shm.view'));
        $this->assertTrue($superadmin->hasPermission('anggota.view'));
        $this->assertTrue($superadmin->hasPermission('dashboard.view'));

        $allPerms = $superadmin->effectivePermissions()->pluck('name')->all();
        $this->assertContains('shm.view', $allPerms);
        $this->assertContains('anggota.view', $allPerms);
        $this->assertContains('dashboard.view', $allPerms);
    }

    public function test_gate_before_authorizes_superadmin(): void
    {
        $superadminRole = Role::query()->firstOrCreate(
            ['name' => Role::SYSTEM_SUPERADMIN],
            ['display_name' => 'Superadmin', 'entity' => 'shared', 'is_active' => true, 'is_system' => true]
        );
        $superadmin = User::factory()->create();
        $superadmin->roles()->attach($superadminRole);

        // Superadmin diotorisasi oleh Gate::before untuk segala ability
        $this->assertTrue(Gate::forUser($superadmin)->allows('shm.view'));
        $this->assertTrue(Gate::forUser($superadmin)->allows('anggota.view'));
        $this->assertTrue(Gate::forUser($superadmin)->allows('dashboard.view'));
    }

    public function test_resolve_entity_context_middleware_route_mapping(): void
    {
        $mw = new ResolveEntityContext;

        // 1. Route SHM resolves to Makro
        $reqShm = Request::create('/shm/pengajuan', 'GET');
        $mw->handle($reqShm, function ($req) {
            $this->assertEquals(EntityContext::Makro, app(EntityContext::class));
            $this->assertEquals(EntityContext::Makro, $req->attributes->get('entity_context'));

            return new Response;
        });

        // 2. Route Anggota resolves to Mikro
        $reqMikro = Request::create('/anggota', 'GET');
        $mw->handle($reqMikro, function ($req) {
            $this->assertEquals(EntityContext::Mikro, app(EntityContext::class));
            $this->assertEquals(EntityContext::Mikro, $req->attributes->get('entity_context'));

            return new Response;
        });

        // 3. Route Pengaturan resolves to Shared
        $reqShared = Request::create('/pengaturan/pengguna', 'GET');
        $mw->handle($reqShared, function ($req) {
            $this->assertEquals(EntityContext::Shared, app(EntityContext::class));
            $this->assertEquals(EntityContext::Shared, $req->attributes->get('entity_context'));

            return new Response;
        });
    }

    public function test_resolve_entity_context_middleware_fails_closed_on_unmapped_route(): void
    {
        $mw = new ResolveEntityContext;
        $reqUnmapped = Request::create('/unmapped-protected-route', 'GET');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Akses ditolak: Konteks entitas rute tidak valid atau tidak terdefinisi.');

        $mw->handle($reqUnmapped, function () {
            return new Response('Should not reach here');
        });
    }

    public function test_explicit_context_override_on_middleware(): void
    {
        $mw = new ResolveEntityContext;
        $req = Request::create('/shm/pengajuan', 'GET');

        // Explicit context override to Mikro
        $mw->handle($req, function ($req) {
            $this->assertEquals(EntityContext::Mikro, app(EntityContext::class));
            $this->assertEquals(EntityContext::Mikro, $req->attributes->get('entity_context'));

            return new Response;
        }, 'mikro');
    }

    public function test_audit_logger_records_entity_context_metadata(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Set context aktif ke Makro
        app()->instance(EntityContext::class, EntityContext::Makro);

        $log = AuditLogger::log(
            'shm.application.create',
            'ShmApplication',
            123,
            'APP-001',
            'Pengajuan SHM dibuat'
        );

        $this->assertNotNull($log->changes);
        $this->assertEquals('makro', $log->changes['_entity_context']);
    }
}
