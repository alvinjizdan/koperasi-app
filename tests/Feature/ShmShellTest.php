<?php

namespace Tests\Feature;

use App\Enums\ShmApplicationStatus;
use App\Models\Loan;
use App\Models\Member;
use App\Models\Role;
use App\Models\SavingsAccount;
use App\Models\ShmApplication;
use App\Models\ShmCreditCheck;
use App\Models\ShmCustomer;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Ticket 48 — SHM application shell & pintu masuk terpisah.
 *
 * Menguji boundary aplikasi SHM di dalam SATU aplikasi Laravel: pintu masuk,
 * halaman masuk, dashboard, layout, otorisasi server-side, dan jaminan bahwa
 * aplikasi koperasi tidak ikut berubah.
 *
 * Catatan pengujian: pilihan layout (ShmLayout vs AdminLayout) adalah keputusan
 * sisi klien dan tidak terlihat dari respons HTTP. Karena itu halaman SHM
 * diperiksa langsung pada berkasnya — tes ini adalah penjaga regresi supaya
 * tidak ada halaman SHM yang diam-diam kembali memakai sidebar koperasi.
 */
class ShmShellTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    private User $admin;

    private User $marketing;

    private User $bm;

    private User $pd;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->superadmin = $this->userWithRole('superadmin');
        $this->admin = $this->userWithRole('admin');
        $this->marketing = $this->userWithRole('marketing');
        $this->bm = $this->userWithRole('branch_manager');
        $this->pd = $this->userWithRole('direktur_utama');
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', $roleName)->value('id'));

        return $user;
    }

    /**
     * Seluruh berkas halaman & komponen SHM di sisi klien.
     *
     * @return array<int, string>
     */
    private function shmFrontendFiles(): array
    {
        $files = glob(base_path('resources/js/Pages/Shm/**/*.jsx')) ?: [];
        $files = array_merge($files, glob(base_path('resources/js/Pages/Shm/*.jsx')) ?: []);
        $files = array_merge($files, glob(base_path('resources/js/Pages/Shm/Auth/*.jsx')) ?: []);
        $files = array_merge($files, glob(base_path('resources/js/Components/Shm/*.jsx')) ?: []);

        sort($files);

        return $files;
    }

    // -----------------------------------------------------------------
    // A. Pintu masuk /shm
    // -----------------------------------------------------------------

    public function test_guest_is_redirected_to_shm_login(): void
    {
        $this->get(route('shm.entry'))
            ->assertRedirect(route('shm.login'));
    }

    public function test_guest_hitting_shm_dashboard_is_redirected_to_shm_login(): void
    {
        // Middleware auth mengarahkan tamu ke pintu masuk SHM, bukan ke
        // halaman login koperasi.
        $this->get(route('shm.dashboard'))
            ->assertRedirect(route('shm.login'));
    }

    public function test_authenticated_shm_user_is_sent_to_shm_dashboard(): void
    {
        $this->actingAs($this->marketing)
            ->get(route('shm.entry'))
            ->assertRedirect(route('shm.dashboard'));
    }

    public function test_admin_and_superadmin_are_sent_to_shm_dashboard(): void
    {
        $this->actingAs($this->admin)
            ->get(route('shm.entry'))
            ->assertRedirect(route('shm.dashboard'));

        $this->actingAs($this->superadmin)
            ->get(route('shm.entry'))
            ->assertRedirect(route('shm.dashboard'));
    }

    public function test_user_without_shm_permission_is_forbidden(): void
    {
        // Mengetik URL tidak cukup: boundary diperiksa server.
        $plain = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($plain)
            ->get(route('shm.entry'))
            ->assertForbidden();

        $this->actingAs($plain)
            ->get(route('shm.dashboard'))
            ->assertForbidden();
    }

    public function test_shm_module_pages_are_forbidden_without_permission(): void
    {
        $plain = User::factory()->create(['email_verified_at' => now()]);

        foreach (['shm.applications.index', 'shm.customers.index', 'shm.credit-checks.index'] as $name) {
            $this->actingAs($plain)
                ->get(route($name))
                ->assertForbidden();
        }
    }

    // -----------------------------------------------------------------
    // B. Halaman masuk SHM
    // -----------------------------------------------------------------

    public function test_guest_can_open_shm_login_page(): void
    {
        $this->get(route('shm.login'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Shm/Auth/Login'));
    }

    public function test_shm_login_reuses_existing_authentication_controller(): void
    {
        $shmLogin = Route::getRoutes()->getByName('shm.login.store');

        // Route POST /login milik koperasi tidak punya nama (hanya GET /login
        // yang dinamai `login`), jadi dicari lewat URI + method.
        $koperasiLogin = collect(Route::getRoutes()->getRoutes())->first(
            fn ($route): bool => $route->uri() === 'login'
                && in_array('POST', $route->methods(), true),
        );

        $this->assertNotNull($shmLogin);
        $this->assertNotNull($koperasiLogin, 'Route POST /login koperasi tidak ditemukan.');

        // Satu implementasi otentikasi: route POST /shm/login menunjuk ke
        // controller yang sama dengan login koperasi (yang membedakan hanya
        // halaman tujuannya).
        $this->assertSame(
            'App\Http\Controllers\Auth\AuthenticatedSessionController@store',
            $shmLogin->getActionName(),
        );
        $this->assertSame($koperasiLogin->getActionName(), $shmLogin->getActionName());
    }

    public function test_authenticated_user_is_redirected_away_from_shm_login(): void
    {
        $this->actingAs($this->marketing)
            ->get(route('shm.login'))
            ->assertRedirect();
    }

    // -----------------------------------------------------------------
    // C. Logout
    // -----------------------------------------------------------------

    public function test_logout_from_shm_returns_to_shm_login(): void
    {
        $this->actingAs($this->marketing)
            ->post(route('logout'), ['context' => 'shm'])
            ->assertRedirect(route('shm.login'));

        $this->assertGuest();
    }

    public function test_logout_from_koperasi_is_unchanged(): void
    {
        $this->actingAs($this->marketing)
            ->post(route('logout'))
            ->assertRedirect('/');

        $this->assertGuest();
    }

    // -----------------------------------------------------------------
    // D. Dashboard
    // -----------------------------------------------------------------

    public function test_dashboard_renders_for_shm_user(): void
    {
        $this->actingAs($this->marketing)
            ->get(route('shm.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Shm/Dashboard')
                ->where('scope', 'own')
                ->has('metrics.customers.total')
                ->has('metrics.applications.by_status')
                ->has('metrics.credit_checking.not_started')
            );
    }

    public function test_dashboard_metrics_come_from_shm_data_only(): void
    {
        // Data koperasi sengaja ada: tidak boleh ikut terhitung.
        Member::factory()->count(3)->create(['full_name' => 'Anggota Koperasi Sentosa']);
        // Nomor pinjaman dibuat berurutan per detik, jadi cukup satu baris:
        // yang penting data koperasi ada dan tidak ikut terhitung.
        Loan::factory()->create();
        SavingsAccount::factory()->create();

        ShmCustomer::factory()->count(2)->create();
        ShmCustomer::factory()->inactive()->create();

        $this->actingAs($this->admin)
            ->get(route('shm.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'all')
                ->where('metrics.customers.total', 3)
                ->where('metrics.customers.active', 2)
                ->where('metrics.applications.total', 0)
                ->where('metrics.credit_checking.completed', 0)
            )
            ->assertDontSee('Anggota Koperasi Sentosa');
    }

    public function test_dashboard_counts_are_scoped_to_owner_without_view_all(): void
    {
        $otherMarketing = $this->userWithRole('marketing');
        $customer = ShmCustomer::factory()->create();

        $this->createApplicationFor($this->marketing, $customer);
        $this->createApplicationFor($otherMarketing, $customer);

        $this->actingAs($this->marketing)
            ->get(route('shm.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'own')
                ->where('metrics.applications.total', 1)
            );

        $this->actingAs($this->admin)
            ->get(route('shm.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('scope', 'all')
                ->where('metrics.applications.total', 2)
            );
    }

    public function test_dashboard_counts_credit_checking_buckets(): void
    {
        $customer = ShmCustomer::factory()->create();

        // Pengajuan pada tahap proses tanpa catatan credit checking.
        $notStarted = $this->createApplicationFor($this->marketing, $customer);

        // Pengajuan yang checking-nya sedang berjalan.
        $running = $this->createApplicationFor($this->marketing, $customer);
        $this->actingAs($this->marketing)
            ->post(route('shm.applications.credit-checking.start', $running->id))
            ->assertSessionHasNoErrors();

        // Pengajuan yang checking-nya sudah selesai.
        $completed = $this->createApplicationFor($this->marketing, $customer);
        $this->actingAs($this->marketing)
            ->post(route('shm.applications.credit-checking.start', $completed->id))
            ->assertSessionHasNoErrors();

        $creditCheck = ShmCreditCheck::query()
            ->where('shm_application_id', $completed->id)
            ->firstOrFail();

        $this->actingAs($this->marketing)
            ->post(route('shm.credit-checks.complete', $creditCheck->id), [
                'outcome' => 'pass',
                'result' => 'Ringkasan',
            ])
            ->assertSessionHasNoErrors();

        // Pengajuan yang sudah dikirim Marketing memang sudah berada di tahap
        // proses, tetapi belum punya baris credit checking.
        $this->assertSame(ShmApplicationStatus::Submitted, $notStarted->refresh()->status);
        $this->assertSame(0, $notStarted->creditChecks()->count());

        $this->actingAs($this->marketing)
            ->get(route('shm.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('metrics.credit_checking.not_started', 1)
                ->where('metrics.credit_checking.in_progress', 1)
                ->where('metrics.credit_checking.completed', 1)
            );
    }

    public function test_dashboard_does_not_expose_fake_business_metrics(): void
    {
        $this->actingAs($this->admin)
            ->get(route('shm.dashboard'))
            ->assertOk()
            ->assertInertia(function ($page) {
                $metrics = json_encode($page->toArray()['props']['metrics'] ?? []);

                foreach (['revenue', 'omzet', 'approval_rate', 'disbursement_amount', 'collection_amount'] as $forbidden) {
                    $this->assertStringNotContainsString(
                        $forbidden,
                        (string) $metrics,
                        "Dashboard SHM tidak boleh memuat metrik rekaan: {$forbidden}.",
                    );
                }

                return $page;
            });
    }

    // -----------------------------------------------------------------
    // E. Layout: halaman SHM memakai shell SHM
    // -----------------------------------------------------------------

    public function test_shm_pages_use_shm_layout(): void
    {
        $pages = array_merge(
            glob(base_path('resources/js/Pages/Shm/*.jsx')) ?: [],
            glob(base_path('resources/js/Pages/Shm/Applications/*.jsx')) ?: [],
            glob(base_path('resources/js/Pages/Shm/Customers/*.jsx')) ?: [],
            glob(base_path('resources/js/Pages/Shm/CreditChecks/*.jsx')) ?: [],
        );

        $this->assertNotEmpty($pages);

        foreach ($pages as $path) {
            $source = (string) file_get_contents($path);
            $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);

            $this->assertStringContainsString(
                '@/Layouts/ShmLayout',
                $source,
                "Halaman SHM {$relative} harus memakai layout SHM.",
            );

            $this->assertStringNotContainsString(
                '@/Layouts/AdminLayout',
                $source,
                "Halaman SHM {$relative} tidak boleh memakai layout koperasi.",
            );
        }
    }

    public function test_shm_layout_uses_shm_sidebar_only(): void
    {
        $layout = (string) file_get_contents(base_path('resources/js/Layouts/ShmLayout.jsx'));

        $this->assertStringContainsString('@/Components/Shm/ShmSidebar', $layout);
        $this->assertStringContainsString('@/Components/Shm/ShmNavbar', $layout);
        $this->assertStringNotContainsString('@/Components/Admin/Sidebar', $layout);
    }

    public function test_shm_sidebar_has_no_dead_links_and_gates_menu_by_permission(): void
    {
        $sidebar = (string) file_get_contents(base_path('resources/js/Components/Shm/ShmSidebar.jsx'));

        // Menu yang belum dibangun tidak diberi route sehingga tidak pernah
        // dirender sebagai tautan mati.
        $this->assertStringContainsString('isReleased', $sidebar);
        // Visibilitas menu mengikuti permission dari server.
        $this->assertStringContainsString('permissions.includes(permission)', $sidebar);
    }

    // -----------------------------------------------------------------
    // F. Kembali ke koperasi
    // -----------------------------------------------------------------

    public function test_switching_back_to_koperasi_keeps_the_session(): void
    {
        $this->actingAs($this->marketing)
            ->get(route('shm.dashboard'))
            ->assertOk();

        // Berpindah area bukan logout: dashboard koperasi tetap dapat dibuka
        // dengan sesi yang sama.
        $this->actingAs($this->marketing)
            ->get(route('dashboard'))
            ->assertOk();

        $this->assertAuthenticatedAs($this->marketing);
    }

    public function test_koperasi_dashboard_still_works_for_every_role(): void
    {
        foreach ([$this->superadmin, $this->admin, $this->marketing, $this->bm, $this->pd] as $user) {
            $this->actingAs($user)
                ->get(route('dashboard'))
                ->assertOk();
        }
    }

    public function test_koperasi_module_routes_are_not_behind_shm_boundary(): void
    {
        // Route koperasi tidak ikut terkena boundary SHM: Branch Manager
        // dapat membuka modul koperasi yang diizinkan.
        $this->actingAs($this->bm)
            ->get(route('anggota.index'))
            ->assertOk();

        // Admin yang memegang kasir.view dapat mengakses kasir
        $this->actingAs($this->admin)
            ->get(route('kasir.index'))
            ->assertOk();
    }

    public function test_koperasi_dashboard_page_still_uses_koperasi_layout(): void
    {
        $dashboard = (string) file_get_contents(base_path('resources/js/Pages/Dashboard.jsx'));

        $this->assertStringContainsString('@/Layouts/AdminLayout', $dashboard);
    }

    public function test_koperasi_sidebar_keeps_its_modules_and_only_points_to_shm_entry(): void
    {
        $sidebar = (string) file_get_contents(base_path('resources/js/Components/Admin/Sidebar.jsx'));

        // Modul koperasi tidak berubah.
        foreach (['simpanan-produk.index', 'kasir.sesi.index', 'pinjaman.index', 'rekonsiliasi.index', 'pengaturan.users.index'] as $routeName) {
            $this->assertStringContainsString($routeName, $sidebar, "Sidebar koperasi kehilangan modul {$routeName}.");
        }

        // SHM kini hanya satu pintu masuk, bukan salinan daftar modul SHM.
        $this->assertStringContainsString('shm.entry', $sidebar);
        $this->assertStringNotContainsString('shm.credit-checks.index', $sidebar);
        $this->assertStringNotContainsString('shm.customers.index', $sidebar);
    }

    // -----------------------------------------------------------------
    // G. Route hygiene
    // -----------------------------------------------------------------

    public function test_every_shm_route_lives_under_the_shm_prefix(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->getName() ?? '', 'shm.')) {
                continue;
            }

            $this->assertTrue(
                str_starts_with($route->uri(), 'shm'),
                "Route {$route->getName()} harus berada di bawah prefix /shm, bukan {$route->uri()}.",
            );
        }
    }

    public function test_shm_route_surface_has_no_duplicates(): void
    {
        $seen = [];

        foreach (Route::getRoutes() as $route) {
            if ($route->getName() === null) {
                continue;
            }

            $this->assertArrayNotHasKey(
                $route->getName(),
                $seen,
                "Nama route ganda: {$route->getName()}.",
            );

            $seen[$route->getName()] = true;

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                $key = $method.' '.$route->uri();
                $this->assertArrayNotHasKey($key, $seen, "Route ganda: {$key}.");
                $seen[$key] = true;
            }
        }
    }

    public function test_no_shm_page_links_to_a_missing_route(): void
    {
        $checked = 0;

        foreach ($this->shmFrontendFiles() as $path) {
            $source = (string) file_get_contents($path);

            preg_match_all("/route\\(\\s*'([^']+)'/", $source, $matches);

            foreach (array_unique($matches[1]) as $name) {
                $this->assertTrue(
                    Route::has($name),
                    basename($path)." menautkan route yang tidak ada: {$name}.",
                );
                $checked++;
            }
        }

        $this->assertGreaterThan(0, $checked, 'Tidak ada tautan route yang diperiksa.');
    }

    public function test_shm_application_index_moved_to_pengajuan(): void
    {
        $this->assertSame('shm/pengajuan', Route::getRoutes()->getByName('shm.applications.index')->uri());
        $this->assertSame('shm', Route::getRoutes()->getByName('shm.entry')->uri());
        $this->assertSame('shm/dashboard', Route::getRoutes()->getByName('shm.dashboard')->uri());
        $this->assertSame('shm/login', Route::getRoutes()->getByName('shm.login')->uri());
    }

    // -----------------------------------------------------------------
    // H. Regresi Ticket 47 di dalam shell SHM
    // -----------------------------------------------------------------

    public function test_credit_checking_flow_still_works_inside_shm_shell(): void
    {
        $customer = ShmCustomer::factory()->create();
        $application = $this->createApplicationFor($this->marketing, $customer);

        $this->actingAs($this->marketing)
            ->get(route('shm.credit-checks.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Shm/CreditChecks/Index'));

        $this->actingAs($this->marketing)
            ->post(route('shm.applications.credit-checking.start', $application->id))
            ->assertSessionHasNoErrors();

        $creditCheck = ShmCreditCheck::query()->firstOrFail();

        $this->actingAs($this->marketing)
            ->post(route('shm.credit-checks.complete', $creditCheck->id), [
                'outcome' => 'review',
                'result' => 'Perlu peninjauan',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('completed', $creditCheck->refresh()->status->value);

        $this->actingAs($this->marketing)
            ->get(route('shm.credit-checks.results'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Shm/CreditChecks/Results')
                ->has('results.data', 1)
            );
    }

    public function test_shm_permission_boundary_is_server_side(): void
    {
        // Menu boleh disembunyikan di klien, tetapi middleware tetap menolak
        // permintaan langsung ke endpoint SHM bagi yang tidak berwenang.
        $plain = User::factory()->create(['email_verified_at' => now()]);
        $customer = ShmCustomer::factory()->create();

        $this->actingAs($plain)
            ->get(route('shm.customers.show', $customer->id))
            ->assertForbidden();

        $this->assertTrue($this->marketing->hasPermission('shm.customer.view'));
        $this->assertFalse($this->marketing->hasPermission('shm.view_all'));
        $this->assertFalse($plain->hasPermission('shm.view'));
    }

    public function test_shm_dashboard_shares_only_permissions_the_user_holds(): void
    {
        $this->actingAs($this->marketing)
            ->get(route('shm.dashboard'))
            ->assertOk()
            ->assertInertia(function ($page) {
                $permissions = $page->toArray()['props']['auth']['permissions'] ?? [];

                $this->assertContains('shm.customer.view', $permissions);
                $this->assertContains('shm.credit_checking.view', $permissions);
                $this->assertNotContains('shm.approval.decide', $permissions);
                $this->assertNotContains('shm.disburse', $permissions);
                $this->assertNotContains('shm.view_all', $permissions);

                return $page;
            });
    }

    /**
     * Buat pengajuan milik Marketing melalui HTTP agar workflow aslinya ikut jalan.
     */
    private function createApplicationFor(User $user, ShmCustomer $customer): ShmApplication
    {
        $this->actingAs($user)
            ->post(route('shm.applications.store'), [
                'shm_customer_id' => $customer->id,
                'notes' => 'Pengajuan shell test',
            ])
            ->assertSessionHasNoErrors();

        $application = ShmApplication::query()->latest('id')->firstOrFail();

        $this->actingAs($user)
            ->post(route('shm.applications.submit', $application->id))
            ->assertSessionHasNoErrors();

        return $application->refresh();
    }
}
