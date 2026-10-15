<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionRegistry;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Struktur role pengguna setelah penyesuaian kebutuhan operasional KSP.
 *
 * Kontrak yang diuji:
 * - role `marketing` tersedia dan memakai mekanisme role/permission existing;
 * - role `petugas_simpanan` dan `petugas_pinjaman` tidak lagi aktif: pada
 *   instalasi yang sudah memakainya, barisnya dinonaktifkan (tidak dihapus)
 *   sehingga riwayat tetap utuh, dan role tersebut tidak dapat ditugaskan lagi;
 * - pada instalasi baru, kedua role tersebut memang tidak pernah dibuat;
 * - role lain tetap berfungsi normal dan marketing tidak mendapat akses berlebihan.
 */
class RoleStructureTest extends TestCase
{
    use RefreshDatabase;

    /** Role lama yang tidak lagi dipakai; datanya sengaja tidak dihapus. */
    private const RETIRED_ROLES = ['petugas_simpanan', 'petugas_pinjaman'];

    /** Role yang harus tetap tersedia dan aktif. */
    private const ACTIVE_ROLES = ['superadmin', 'admin', 'kasir', 'marketing', 'pengawas'];

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->superadmin = User::factory()->create();
        $this->superadmin->roles()->attach(Role::query()->where('name', 'superadmin')->value('id'));
    }

    /**
     * Simulasikan instalasi lama: kedua role sudah ada, aktif, dan sudah punya
     * pengguna. Setelah seeder dijalankan, keduanya harus dinonaktifkan tanpa
     * dihapus dan tanpa mencabut akses pengguna yang masih memakainya.
     *
     * @return array<string, Role>
     */
    private function simulateLegacyRoles(): array
    {
        $created = [];

        foreach (['petugas_simpanan', 'petugas_pinjaman'] as $name) {
            $role = Role::query()->create([
                'name' => $name,
                'display_name' => ucfirst(str_replace('_', ' ', $name)),
                'is_system' => true,
                'is_active' => true,
            ]);

            $role->permissions()->attach(
                Permission::query()->whereIn('name', ['dashboard.view', 'laporan.view'])->pluck('id')->all()
            );

            $created[$name] = $role;
        }

        $holder = User::factory()->create();
        $holder->roles()->attach($created['petugas_pinjaman']->id);

        $this->seed(RoleSeeder::class);

        return $created;
    }

    /** 1. Role marketing tersedia sebagai role sistem yang aktif. */
    public function test_marketing_role_is_available_and_active(): void
    {
        $marketing = Role::query()->where('name', 'marketing')->first();

        $this->assertNotNull($marketing, 'Role marketing harus dibuat oleh RoleSeeder.');
        $this->assertTrue($marketing->is_system);
        $this->assertTrue($marketing->is_active);
        $this->assertSame('Marketing', $marketing->display_name);
    }

    /** 2. Pada instalasi baru, role lama tidak pernah dibuat sama sekali. */
    public function test_retired_roles_are_absent_on_fresh_installation(): void
    {
        foreach (['petugas_simpanan', 'petugas_pinjaman'] as $name) {
            $this->assertNull(Role::query()->where('name', $name)->first());
        }

        $this->assertSame(count(self::ACTIVE_ROLES), Role::query()->count());
    }

    /** 3. Role lama yang sudah ada dinonaktifkan, bukan dihapus. */
    public function test_existing_retired_roles_are_deactivated_not_deleted(): void
    {
        $this->simulateLegacyRoles();

        foreach (['petugas_simpanan', 'petugas_pinjaman'] as $name) {
            $role = Role::query()->where('name', $name)->first();

            $this->assertNotNull($role, "Baris role {$name} harus tetap tersimpan (tidak dihapus).");
            $this->assertFalse($role->is_active, "Role {$name} harus dinonaktifkan.");
            $this->assertGreaterThan(0, $role->permissions()->count(), 'Definisi permission role lama tetap tersimpan.');
        }
    }

    /** 4. Role bawaan yang lain tetap aktif dan tidak terganggu. */
    public function test_standard_roles_remain_active(): void
    {
        $this->simulateLegacyRoles();

        foreach (self::ACTIVE_ROLES as $name) {
            $role = Role::query()->where('name', $name)->first();

            $this->assertNotNull($role, "Role {$name} harus tersedia.");
            $this->assertTrue($role->is_active, "Role {$name} harus tetap aktif.");
        }
    }

    /** 5. Role marketing hanya mendapat hak akses baca yang relevan. */
    public function test_marketing_role_has_only_least_privilege_read_access(): void
    {
        // Role marketing: akses baca modul relevan + aktivitas kunjungan
        // sendiri dan titik lokasi anggota + pengajuan SHM miliknya sendiri.
        // Tetap TIDAK ada authority atas modul lain maupun hak memantau
        // Marketing lain.
        //
        // Ticket 44 menambah permission shm.* yang seluruhnya berlingkup pada
        // pengajuan milik Marketing sendiri. shm.view_all, shm.approval.decide,
        // shm.disburse, dan shm.complete SENGAJA tidak diberikan karena batas
        // kewenangan approval dan pencairan masih PENDING BUSINESS DECISION.
        $expected = [
            'dashboard.view',
            'anggota.view',
            'pinjaman.view',
            'angsuran.view',
            'marketing.view',
            'marketing.create',
            'marketing.result.update',
            'marketing.member_location.view',
            'shm.view',
            'shm.create',
            'shm.update',
            'shm.submit',
            'shm.property.manage',
            // Ticket 47: Marketing mengerjakan credit checking pengajuan miliknya
            // sendiri. Wewenang MELIHAT dipisahkan dari wewenang MENGERJAKAN,
            // tetapi keduanya tetap dalam lingkup pengajuan miliknya.
            'shm.credit_checking.view',
            'shm.credit_checking.update',
            'shm.memorandum.update',
            'shm.document.update',
            // Ticket 46: Marketing mengelola master nasabah SHM karena nasabah
            // SHM dipilih/dibuat saat membuat pengajuan. Master nasabah bukan
            // anggota koperasi, sehingga hak ini tidak menyentuh keanggotaan.
            'shm.customer.view',
            'shm.customer.create',
            'shm.customer.update',
            // Ticket 101: siklus Collection (view/transition/activity/PTP)
            // kini berupa permission per-aksi yang configurable; Marketing
            // tetap owner awal case dan ownership di service tetap berlaku.
            'shm.collection.view',
            'shm.collection.transition',
            'shm.collection.activity.create',
            'shm.collection.ptp.create',
            'shm.collection.ptp.transition',
        ];
        $actual = Role::query()
            ->where('name', 'marketing')
            ->first()
            ->permissions()
            ->pluck('permissions.name')
            ->sort()
            ->values()
            ->all();

        sort($expected);

        $this->assertSame($expected, $actual, 'Hak akses role marketing harus tetap persis seperti yang disepakati.');
    }

    /** 6. Marketing tidak boleh dapat menulis di modul mana pun. */
    public function test_marketing_role_has_no_write_or_authority_permission(): void
    {
        $marketing = User::factory()->create();
        $marketing->roles()->attach(Role::query()->where('name', 'marketing')->value('id'));

        $forbidden = [
            'simpanan.create', 'simpanan.deposit', 'simpanan.withdraw', 'simpanan.reverse',
            'kasir.open_session', 'kasir.receive', 'kasir.disburse', 'kasir.deposit', 'kasir.close_session', 'kasir.reverse',
            'pinjaman.create', 'pinjaman.approve', 'pinjaman.reject', 'pinjaman.disburse',
            'angsuran.record_payment', 'angsuran.reverse',
            'laporan.export',
            'pengguna.create', 'pengguna.update', 'pengguna.assign_role', 'pengguna.reset_password',
            'role.create', 'role.update', 'role.delete',
            'pengaturan.update', 'audit.view',
            'rekonsiliasi.run', 'rekonsiliasi.resolve',
            'accounting.accounts.manage', 'accounting.journals.create', 'accounting.journals.post',
            // Ticket 44: Marketing tidak boleh memantau pengajuan orang lain
            // maupun mengambil keputusan approval dan pencairan.
            'shm.view_all', 'shm.approval.decide', 'shm.disburse', 'shm.complete',
        ];

        foreach ($forbidden as $permission) {
            $this->assertFalse(
                $marketing->fresh()->hasPermission($permission),
                "Role marketing tidak boleh memiliki {$permission}."
            );
        }
    }

    /** 7. Role nonaktif tidak dapat lagi ditugaskan lewat halaman pengguna. */
    public function test_retired_role_cannot_be_assigned_to_user(): void
    {
        $this->simulateLegacyRoles();

        $target = User::factory()->create();
        $retiredId = Role::query()->where('name', 'petugas_simpanan')->value('id');

        $this->actingAs($this->superadmin)
            ->patch(route('pengaturan.users.update-roles', $target->id), ['roles' => [$retiredId]])
            ->assertSessionHasErrors('roles.0');

        $this->assertCount(0, $target->fresh()->roles, 'Role target tidak boleh berubah.');
    }

    /** 8. Role nonaktif juga ditolak saat membuat pengguna baru. */
    public function test_retired_role_cannot_be_assigned_on_user_creation(): void
    {
        $this->simulateLegacyRoles();

        $retiredId = Role::query()->where('name', 'petugas_pinjaman')->value('id');

        $this->actingAs($this->superadmin)
            ->post(route('pengaturan.users.store'), [
                'name' => 'Uji Role Lama',
                'email' => 'uji-role-lama@ksp.test',
                'password' => 'rahasia123',
                'roles' => [$retiredId],
            ])
            ->assertSessionHasErrors('roles.0');

        $this->assertDatabaseMissing('users', ['email' => 'uji-role-lama@ksp.test']);
    }

    /** 9. Menonaktifkan role TIDAK mencabut akses pengguna yang masih memakainya. */
    public function test_inactive_role_keeps_its_existing_permissions(): void
    {
        // Keputusan sengaja: menonaktifkan role bukan mencabut haknya. Jika
        // masih ada pengguna yang memakainya, aksesnya tidak hilang diam-diam
        // sehingga tidak ada pengguna yang kehilangan akses secara tidak sengaja.
        $this->simulateLegacyRoles();

        $holder = User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', 'petugas_pinjaman'))
            ->firstOrFail();

        $this->assertFalse(Role::query()->where('name', 'petugas_pinjaman')->first()->is_active);
        $this->assertTrue(
            $holder->fresh()->hasPermission('laporan.view'),
            'Pengguna yang masih memegang role lama tidak boleh kehilangan aksesnya.'
        );
    }

    /** 10. Menjalankan seeder berulang tetap aman dan tidak membuat duplikasi. */
    public function test_role_seeding_is_idempotent(): void
    {
        $this->simulateLegacyRoles();
        $before = Role::query()->count();

        $this->seed(RoleSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->assertSame($before, Role::query()->count(), 'Seeder tidak boleh membuat role duplikat.');
        $this->assertTrue(Role::query()->where('name', 'marketing')->first()->is_active);
        $this->assertFalse(Role::query()->where('name', 'petugas_simpanan')->first()->is_active);
    }

    /** 11. Halaman role menandai role nonaktif tanpa menyembunyikannya. */
    public function test_role_index_marks_inactive_roles(): void
    {
        $this->simulateLegacyRoles();

        $this->actingAs($this->superadmin)
            ->get(route('pengaturan.roles.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Pengaturan/Roles/Index')
                ->has('roles', 7)
                ->where('roles', fn ($roles) => collect($roles)->firstWhere('name', 'marketing')['is_active'] === true)
                ->where('roles', fn ($roles) => collect($roles)->firstWhere('name', 'petugas_simpanan')['is_active'] === false)
            );
    }

    /** 12. Form pengguna hanya menawarkan role yang aktif. */
    public function test_user_forms_only_offer_active_roles(): void
    {
        $this->simulateLegacyRoles();

        $this->actingAs($this->superadmin)
            ->get(route('pengaturan.users.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Pengaturan/Users/Create')
                ->has('roles', 5)
                ->where('roles', function ($roles) {
                    $expected = self::ACTIVE_ROLES;
                    sort($expected);

                    return collect($roles)->pluck('name')->sort()->values()->all() === $expected;
                })
            );
    }

    /** 13. Pengguna role marketing dapat login dan hanya membuka modul yang diizinkan. */
    public function test_marketing_user_can_login_and_reach_only_allowed_modules(): void
    {
        $marketing = User::factory()->create([
            'name' => 'Petugas Marketing',
            'email_verified_at' => now(),
        ]);
        $marketing->roles()->attach(Role::query()->where('name', 'marketing')->value('id'));

        $this->actingAs($marketing)->get(route('dashboard'))->assertOk();
        $this->actingAs($marketing)->get(route('anggota.index'))->assertOk();
        $this->actingAs($marketing)->get(route('pinjaman.index'))->assertOk();
        $this->actingAs($marketing)->get(route('angsuran.index'))->assertOk();

        // Modul di luar hak akses marketing tetap tertutup.
        $this->actingAs($marketing)->get(route('kasir.index'))->assertForbidden();
        $this->actingAs($marketing)->get(route('simpanan.index'))->assertForbidden();
        $this->actingAs($marketing)->get(route('rekonsiliasi.index'))->assertForbidden();
        $this->actingAs($marketing)->get(route('pengaturan.roles.index'))->assertForbidden();
    }

    /** 14. Permission registry tetap sinkron dengan config/permissions.php. */
    public function test_permission_registry_matches_config(): void
    {
        $registered = app(PermissionRegistry::class)->allNames();
        sort($registered);

        $inDatabase = Permission::query()->pluck('name')->all();
        sort($inDatabase);

        $this->assertSame(
            $registered,
            $inDatabase,
            'Permission pada database harus sama persis dengan registry pada config/permissions.php.'
        );
    }
}
