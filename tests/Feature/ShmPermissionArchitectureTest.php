<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionRegistry;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Ticket 101 — Arsitektur permission SHM configurable via UI Role & Permission.
 *
 * Mengunci:
 * - Permission Collection (`shm.collection.*`) terdaftar pada registry
 *   config + tabel permissions (Seeder sinkron otomatis).
 * - Permission dapat DIBERIKAN dan DICABUT lewat endpoint UI Role &
 *   Permission (pengaturan.roles.update), lalu langsung berlaku di backend
 *   authorization (allow → 200, revoke → 403).
 * - Route collection memakai permission per-aksi; basis lama `shm.view`
 *   sahaja TIDAK lagi cukup (capability Collection terpisah & configurable).
 * - Superadmin tetap bypass tanpa permission eksplisit (Gate::before) dan
 *   memegang seluruh permission efektif (visibilitas sidebar).
 * - Role koperasi (kasir) tidak memperoleh permission collection tanpa
 *   pemberian eksplisit; role marketing (default) tetap kompatibel.
 *
 * Ownership pemilik case (guard kedua) TIDAK diuji ulang di sini — tetap
 * dikunci oleh ShmCollectionPtpEnforcementTest (test 29-30) dan
 * ShmCollectionOwnershipEscalationTest, yang kini berjalan DENGAN route
 * permission terpenuhi sehingga bukti "permission ≠ ownership" justru
 * menjadi lebih kuat.
 */
class ShmPermissionArchitectureTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private const COLLECTION_PERMISSIONS = [
        'shm.collection.view',
        'shm.collection.transition',
        'shm.collection.activity.create',
        'shm.collection.ptp.create',
        'shm.collection.ptp.transition',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
    }

    // ------------------------------------------------------------------
    // A. Permission registration
    // ------------------------------------------------------------------

    public function test_collection_permissions_are_registered_in_config_and_database(): void
    {
        $registry = app(PermissionRegistry::class);

        foreach (self::COLLECTION_PERMISSIONS as $name) {
            $this->assertTrue($registry->isValid($name), "{$name} harus terdaftar di registry config.");
            $this->assertDatabaseHas('permissions', ['name' => $name]);
        }

        // Tidak ada permission collection di luar lima di atas.
        $expected = self::COLLECTION_PERMISSIONS;
        sort($expected);

        $this->assertSame(
            $expected,
            Permission::query()
                ->where('name', 'like', 'shm.collection.%')
                ->orderBy('name')
                ->pluck('name')
                ->all(),
        );
    }

    // ------------------------------------------------------------------
    // B + C. Role assignment & removal lewat UI Role & Permission
    // ------------------------------------------------------------------

    public function test_collection_permission_can_be_granted_and_revoked_through_role_ui(): void
    {
        $admin = $this->userWithRole('admin');
        $tester = $this->userWithPermissions([]);
        $role = $tester->roles()->firstOrFail();

        // Grant melalui endpoint UI Role & Permission yang sama.
        $this->actingAs($admin)
            ->put(route('pengaturan.roles.update', $role->id), [
                'display_name' => $role->display_name,
                'description' => $role->description,
                'permissions' => ['shm.collection.view'],
            ])
            ->assertSessionHas('success');

        $this->assertTrue($tester->fresh()->hasPermission('shm.collection.view'));
        $this->actingAs($tester)->get(route('shm.collection.index'))->assertOk();

        // Revoke melalui endpoint yang sama.
        $this->actingAs($admin)
            ->put(route('pengaturan.roles.update', $role->id), [
                'display_name' => $role->display_name,
                'description' => $role->description,
                'permissions' => [],
            ])
            ->assertSessionHas('success');

        $this->assertFalse($tester->fresh()->hasPermission('shm.collection.view'));
        $this->actingAs($tester)->get(route('shm.collection.index'))->assertForbidden();
    }

    // ------------------------------------------------------------------
    // D. Backend authorization — permission per-aksi, bukan shm.view saja
    // ------------------------------------------------------------------

    public function test_collection_routes_use_specific_permissions_not_bare_shm_view(): void
    {
        $expected = [
            'shm.collection.index' => 'permission:shm.collection.view',
            'shm.collection.show' => 'permission:shm.collection.view',
            'shm.collection.activities.store' => 'permission:shm.collection.activity.create',
            'shm.collection.cases.transition' => 'permission:shm.collection.transition',
            'shm.collection.ptp.store' => 'permission:shm.collection.ptp.create',
            'shm.collection.ptp.transition' => 'permission:shm.collection.ptp.transition',
        ];

        foreach ($expected as $routeName => $middleware) {
            $route = Route::getRoutes()->getByName($routeName);

            $this->assertNotNull($route, "Route {$routeName} harus terdaftar.");
            $this->assertContains($middleware, $route->gatherMiddleware(), "Route {$routeName} harus memakai {$middleware}.");
            $this->assertNotContains('permission:shm.view', $route->gatherMiddleware(), "Route {$routeName} tidak lagi cukup dengan shm.view sahaja.");
        }

        // Basis lama (shm.view tanpa hak collection) kini ditolak — capability
        // Collection benar-benar terpisah dan configurable.
        $oldBasis = $this->userWithPermissions(['shm.view']);

        $this->actingAs($oldBasis)->get(route('shm.collection.index'))->assertForbidden();
    }

    // ------------------------------------------------------------------
    // E. Superadmin bypass
    // ------------------------------------------------------------------

    public function test_superadmin_bypasses_collection_permission_without_explicit_grant(): void
    {
        $superadminRole = Role::query()->where('name', Role::SYSTEM_SUPERADMIN)->firstOrFail();
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach($superadminRole->id);

        $this->actingAs($user)->get(route('shm.collection.index'))->assertOk();

        // Sidebar memakai effectivePermissions: superadmin melihat menu
        // Collection tanpa pemberian permission eksplisit.
        $this->assertTrue(
            $user->fresh()->effectivePermissions()->pluck('name')->contains('shm.collection.view'),
        );
    }

    // ------------------------------------------------------------------
    // F. Permission muncul di UI Role & Permission
    // ------------------------------------------------------------------

    public function test_role_and_permission_ui_lists_shm_module_and_collection_permissions(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get(route('pengaturan.roles.permissions'))
            ->assertOk()
            // Slash module label di-escape sebagai `\/` dalam payload JSON.
            ->assertSee('SHM\/SHGB', false)
            ->assertSee('shm.collection.view')
            ->assertSee('shm.collection.ptp.transition');
    }

    // ------------------------------------------------------------------
    // H. Existing Koperasi tidak rusak + kompatibilitas role default
    // ------------------------------------------------------------------

    public function test_koperasi_role_does_not_hold_collection_permission_unless_granted(): void
    {
        $pd = $this->userWithRole('direktur_utama');

        $this->assertFalse($pd->hasPermission('shm.collection.view'));
        $this->actingAs($pd)->get(route('shm.collection.index'))->assertForbidden();
    }

    public function test_marketing_default_role_keeps_collection_access(): void
    {
        $marketing = $this->userWithRole('marketing');

        foreach (self::COLLECTION_PERMISSIONS as $name) {
            $this->assertTrue($marketing->hasPermission($name), "Role marketing harus tetap memegang {$name} (kompatibilitas T90-T99).");
        }

        $this->actingAs($marketing)->get(route('shm.collection.index'))->assertOk();
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach(
            Role::where('name', $roleName)->firstOrFail(),
        );

        return $user;
    }

    /**
     * @param  array<int, string>  $permissionNames
     */
    private function userWithPermissions(array $permissionNames): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $role = Role::query()->create([
            'name' => 't101-'.uniqid(),
            'display_name' => 'T101 Permission Tester',
            'is_system' => false,
        ]);

        $role->permissions()->attach(
            Permission::query()->whereIn('name', $permissionNames)->pluck('id'),
        );
        $user->roles()->attach($role->id);

        return $user;
    }
}
