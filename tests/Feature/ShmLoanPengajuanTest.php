<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Ticket 66 — Menu Pinjaman dropdown + halaman Pengajuan Pinjaman 1 halaman.
 *
 * Yang diuji:
 * - route pengajuan terdaftar SEBELUM wildcard {loan} dan dirender sebagai
 *   SATU halaman Inertia;
 * - otorisasi memakai permission shm.view existing (tamu & tanpa permission
 *   ditolak server-side);
 * - ke-11 section tersedia dalam satu berkas halaman;
 * - accordion memakai state React lokal (tanpa request/navigasi backend);
 * - perhitungan penghasilan-pengeluaran memakai rumus sisa yang benar dan
 *   rate default legacy 2% (bukan nilai dari screenshot);
 * - tidak ada dependency ke anggota/simpanan koperasi untuk customer SHM;
 * - permukaan route loans & approval tidak diduplikasi;
 * - struktur sidebar Pinjaman sesuai target (7 anak, placeholder tanpa route).
 *
 * Yang TIDAK diuji: penyimpanan pengajuan (endpoint store ada — diuji di
 * ShmLoanApplicationTest) dan pembangunan jadwal angsuran (baseline T79
 * flat per bulan; diuji di ShmRepaymentScheduleTest).
 */
class ShmLoanPengajuanTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    private User $admin;

    private User $marketing;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->superadmin = $this->userWithRole('superadmin');
        $this->admin = $this->userWithRole('admin');
        $this->marketing = $this->userWithRole('marketing');
        $this->kasir = $this->userWithRole('kasir');
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', $roleName)->value('id'));

        return $user;
    }

    private function pageSource(): string
    {
        return (string) file_get_contents(
            base_path('resources/js/Pages/Shm/Loans/Pengajuan.jsx')
        );
    }

    // -----------------------------------------------------------------
    // A. Route & render satu halaman
    // -----------------------------------------------------------------

    public function test_pengajuan_route_is_registered_before_the_loan_wildcard(): void
    {
        $this->assertTrue(Route::has('shm.loans.pengajuan'));
        $this->assertSame(
            'shm/pinjaman/pengajuan',
            Route::getRoutes()->getByName('shm.loans.pengajuan')->uri(),
        );

        // Literal /pinjaman/pengajuan harus menang sebelum /pinjaman/{loan}:
        // bila tertelan wildcard, route model binding akan mencari record
        // dengan "pengajuan" dan menghasilkan 404.
        $this->actingAs($this->admin)
            ->get('/shm/pinjaman/pengajuan')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Shm/Loans/Pengajuan'));
    }

    public function test_pengajuan_page_is_a_single_page_with_expected_props(): void
    {
        $this->actingAs($this->admin)
            ->get(route('shm.loans.pengajuan'))
            ->assertOk()
            ->assertInertia(function (Assert $page): bool {
                $page->component('Shm/Loans/Pengajuan')
                    // T82 — tidak ada prop rate universal; rate diturunkan
                    // dari tier produk (product + tenor) di sisi klien.
                    ->missing('rate')
                    ->where('today', now()->format('Y-m-d'))
                    ->has('products')
                    ->has('customers')
                    ->has('options.methods')
                    ->has('options.periods')
                    ->etc();

                return true;
            });
    }

    // -----------------------------------------------------------------
    // B. Otorisasi
    // -----------------------------------------------------------------

    public function test_access_is_controlled_by_existing_shm_permission(): void
    {
        $this->get(route('shm.loans.pengajuan'))->assertRedirect(route('shm.login'));

        $this->actingAs($this->kasir)->get(route('shm.loans.pengajuan'))->assertForbidden();

        foreach ([$this->marketing, $this->admin, $this->superadmin] as $user) {
            $this->actingAs($user)
                ->get(route('shm.loans.pengajuan'))
                ->assertOk();
        }
    }

    // -----------------------------------------------------------------
    // C. 11 section dalam satu berkas halaman
    // -----------------------------------------------------------------

    public function test_all_eleven_sections_exist_in_one_page_file(): void
    {
        $source = $this->pageSource();

        $sections = [
            'Data Pribadi',
            'Pengajuan Pinjaman',
            'Data Penghasilan dan Pengeluaran Tiap Bulan',
            'Data Tambahan',
            'Jaminan Pinjaman',
            'Keterangan / Catatan oleh Kasir / SC',
            'Analisa Pinjaman',
            'Data Tambahan Analisa Pinjaman',
            'Kolektor Pinjaman',
            'Persetujuan Pinjaman',
            'Persyaratan Tambahan Jika Pinjaman Disetujui',
        ];

        foreach ($sections as $title) {
            $this->assertStringContainsString(
                $title,
                $source,
                "Section \"{$title}\" tidak ada di halaman Pengajuan Pinjaman.",
            );
        }

        // Definisi section berada di SATU daftar (bukan 11 halaman terpisah).
        $this->assertStringContainsString('const SECTIONS = [', $source);
        $this->assertSame(
            11,
            preg_match('/const SECTIONS = \[(.*?)\];/s', $source, $matches)
                ? substr_count($matches[1], 'key:')
                : 0,
            'Daftar SECTIONS harus berisi tepat 11 entri.',
        );
    }

    public function test_sidebar_pinjaman_menu_matches_target_structure(): void
    {
        $sidebar = (string) file_get_contents(
            base_path('resources/js/Components/Shm/ShmSidebar.jsx')
        );

        // 7 anak menu Pinjaman sesuai struktur target.
        foreach ([
            'Pengajuan Pinjaman',
            'Daftar Pinjaman',
            'Simulasi Pinjaman',
            'Jaminan',
            'Pembayaran / Angsuran',
            'Pelunasan',
            'Laporan',
        ] as $child) {
            $this->assertStringContainsString(
                $child,
                $sidebar,
                "Anak menu Pinjaman \"{$child}\" tidak ada.",
            );
        }

        $this->assertStringContainsString('shm.loans.pengajuan', $sidebar);

        // Item yang belum dibangun tidak diberi route palsu (ShmShellTest
        // juga memverifikasi setiap route() di sidebar benar-benar ada).
        foreach (['shm.jaminan', 'shm.pelunasan', 'shm.laporan', 'shm.angsuran'] as $fake) {
            $this->assertStringNotContainsString($fake, $sidebar);
        }
    }

    // -----------------------------------------------------------------
    // D. Accordion = state lokal, bukan navigasi/request
    // -----------------------------------------------------------------

    public function test_accordion_uses_local_state_without_navigation_or_backend_request(): void
    {
        $source = $this->pageSource();

        $this->assertStringContainsString('useState', $source);
        $this->assertStringContainsString('aria-expanded', $source);
        $this->assertStringContainsString('Buka Semua', $source);
        $this->assertStringContainsString('Tutup Semua', $source);

        // Toggle accordion adalah update state murni: blok `toggleSection`
        // tidak pernah menyentuh router/network — membuka/menutup section
        // bukan navigasi route maupun request backend (accordion contract T66)
        // meski form kini disubmit ke backend (Ticket 67).
        $this->assertSame(
            1,
            preg_match(
                '/const toggleSection = \(key\) =>(.*?)(?:\n    const |\n    \/\/)/s',
                $source,
                $matches,
            ),
            'toggleSection tidak ditemukan.',
        );
        $this->assertStringNotContainsString('router', $matches[1]);
        $this->assertStringNotContainsString('fetch', $matches[1]);
        $this->assertStringNotContainsString('route(', $matches[1]);
    }

    // -----------------------------------------------------------------
    // E. Perhitungan cashflow & rate produk (T82 — bukan universal 2%)
    // -----------------------------------------------------------------

    public function test_income_expense_remainder_uses_the_documented_formula(): void
    {
        $source = $this->pageSource();

        // Sisa penghasilan = total penghasilan − total pengeluaran.
        $this->assertStringContainsString('totalPenghasilan - totalPengeluaran', $source);

        // Perhitungan memakai integer parsing, bukan parseFloat sembrono.
        $this->assertStringContainsString('Number.parseInt', $source);
        $this->assertStringNotContainsString('parseFloat', $source);
    }

    public function test_income_note_states_current_behavior_not_pending(): void
    {
        $source = $this->pageSource();

        // T100 — perilaku cashflow dijelaskan faktual (perhitungan ada,
        // tanpa ambang minimum/DSCR/eligibility tambahan saat ini).
        $this->assertStringContainsString('Saat ini sistem belum', $source);
        $this->assertStringContainsString('ambang minimum penghasilan, DSCR', $source);

        // Wording lama income/expense ("semuanya PENDING BUSINESS
        // CONFIRMATION") tidak lagi ada pada catatan cashflow. Catatan
        // pending LAIN (credit scoring, jaminan, analisa-tambahan,
        // DITANGGUHKAN) tetap dipertahankan di halaman ini.
        $this->assertStringNotContainsString('ambang minimum, DSCR, atau aturan', $source);
        $this->assertStringContainsString('PENDING BUSINESS CONFIRMATION', $source);
    }

    public function test_pengajuan_page_shows_product_driven_rate_not_universal_two_percent(): void
    {
        // T82 — konstanta legacy masih ada (simulasi) tetapi halaman
        // pengajuan TIDAK lagi menerima/menampilkan rate universal 2%.
        $this->assertSame(2.0, ShmLoan::OFFICIAL_RATE_PERCENT);

        $this->actingAs($this->admin)
            ->get(route('shm.loans.pengajuan'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Loans/Pengajuan')
                ->missing('rate')
                ->etc());

        $source = $this->pageSource();

        // Nilai contoh screenshot (16%) tidak pernah menjadi default/konstanta.
        $this->assertStringNotContainsString('16%', $source);
        $this->assertStringNotContainsString('460.000.000', $source);
        // Opsi metode angsuran berasal dari service simulasi existing,
        // bukan daftar baru yang dibuat halaman ini.
        $this->assertStringContainsString('options.methods', $source);
        $this->assertStringContainsString('PENDING BUSINESS CONFIRMATION', $source);
    }

    // -----------------------------------------------------------------
    // F. Boundary koperasi
    // -----------------------------------------------------------------

    public function test_customer_shm_data_does_not_touch_koperasi_member_or_savings(): void
    {
        $customer = ShmCustomer::factory()->create();

        $this->actingAs($this->admin)
            ->get(route('shm.loans.pengajuan'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Loans/Pengajuan')
                ->where('customers.0.customer_number', $customer->customer_number)
                ->where('customers.0.id', $customer->id)
                ->etc());

        $source = $this->pageSource();

        // Tidak ada rute/import model maupun tabel simpanan koperasi.
        $this->assertStringNotContainsString('App\Models\Member', $source);
        $this->assertStringNotContainsString("route('anggota", $source);
        $this->assertStringNotContainsString('saving_', $source);
        $this->assertStringNotContainsString('simpanan-rekening', $source);
    }

    // -----------------------------------------------------------------
    // G. Tidak ada duplikasi workflow / permukaan route yang rusak
    // -----------------------------------------------------------------

    public function test_loan_route_surface_is_unchanged_except_the_pengajuan_routes(): void
    {
        // Perubahan T68: +daftar, +edit, +update, +submit-approval —
        // sisanya identik T66/T67. Perubahan T69: +pencairan (POST).
        // Perubahan T77: +jaminan/{collateral}/foto (GET, foto jaminan).
        // Perubahan T86: +pembayaran (GET index, GET show, POST store).
        // Perubahan T87: +pembayaran/{payment}/allocate (POST alokasi).
        // Perubahan T89: +pelunasan (GET index, GET show, POST store).
        // Perubahan T89B: +workflow pelunasan (approve/reject/payment/
        // payment.verify/complete) +master policy diskon (index/store/
        // toggle).
        $expected = [
            'shm.loans.index',
            // T86 — transaksi pembayaran pinjaman SHM.
            // T87 — alokasi pembayaran ke angsuran (POST).
            'shm.loans.payments.allocate',
            'shm.loans.payments.index',
            'shm.loans.payments.show',
            'shm.loans.payments.store',
            // T89/T89B/T005 — pelunasan / settlement.
            'shm.loans.pelunasan.approve',
            'shm.loans.pelunasan.complete',
            'shm.loans.pelunasan.correct',
            'shm.loans.pelunasan.correct.preview',
            'shm.loans.pelunasan.index',
            'shm.loans.pelunasan.payment',
            'shm.loans.pelunasan.payment.verify',
            'shm.loans.pelunasan.policies.index',
            'shm.loans.pelunasan.policies.store',
            'shm.loans.pelunasan.policies.toggle',
            'shm.loans.pelunasan.policies.update',
            'shm.loans.pelunasan.reject',
            'shm.loans.pelunasan.show',
            'shm.loans.pelunasan.store',
            'shm.loans.pengajuan',
            'shm.loans.pengajuan.daftar',
            'shm.loans.pengajuan.disburse',
            'shm.loans.pengajuan.edit',
            // Dokumen PDF jaminan (ticket upload-dokumen-jaminan).
            'shm.loans.pengajuan.jaminan.dokumen',
            'shm.loans.pengajuan.jaminan.foto',
            'shm.loans.pengajuan.pdf',
            'shm.loans.pengajuan.show',
            'shm.loans.pengajuan.store',
            'shm.loans.pengajuan.submit-approval',
            'shm.loans.pengajuan.update',
            // T98-FIX — CRUD master produk (baca ≠ tulis: shm.product.manage).
            'shm.loans.products.create',
            'shm.loans.products.edit',
            'shm.loans.products.index',
            'shm.loans.products.store',
            'shm.loans.products.update',
            // T83 — action eksplisit pembangkitan jadwal angsuran.
            'shm.loans.schedule.generate',
            'shm.loans.show',
            'shm.loans.simulation',
            'shm.loans.simulation.pdf',
        ];

        $actual = collect(Route::getRoutes())
            ->map->getName()
            ->filter(fn (?string $name): bool => str_starts_with((string) $name, 'shm.loans.'))
            ->sort()
            ->values()
            ->all();

        $this->assertSame($expected, $actual, 'Permukaan route shm.loans.* berubah.');

        // Halaman pengajuan hanya GET; mutasi T67/T68/T69 dibatasi satu
        // POST store, satu PUT update, satu POST ajukan-approval, dan satu
        // POST pencairan.
        $route = Route::getRoutes()->getByName('shm.loans.pengajuan');
        $this->assertSame(['GET', 'HEAD'], $route->methods());

        $store = Route::getRoutes()->getByName('shm.loans.pengajuan.store');
        $this->assertSame(['POST'], $store->methods());

        $update = Route::getRoutes()->getByName('shm.loans.pengajuan.update');
        $this->assertSame(['PUT'], $update->methods());

        $submitApproval = Route::getRoutes()->getByName('shm.loans.pengajuan.submit-approval');
        $this->assertSame(['POST'], $submitApproval->methods());

        $disburse = Route::getRoutes()->getByName('shm.loans.pengajuan.disburse');
        $this->assertSame(['POST'], $disburse->methods());

        // Tidak pernah ada endpoint DELETE pengajuan (di luar scope semua ticket).
        $routeNames = collect(Route::getRoutes())->map->getName();
        $this->assertFalse($routeNames->contains('shm.loans.pengajuan.destroy'));

        // Workflow approval existing tidak diduplikasi.
        $this->assertTrue(Route::has('shm.approvals.index'));
        $source = $this->pageSource();
        $this->assertStringNotContainsString('shm.approvals', $source);
        $this->assertStringNotContainsString("route('shm.applications.approve", $source);
    }

    public function test_simpan_submits_once_to_store_and_is_guarded_while_processing(): void
    {
        $source = $this->pageSource();

        // Submit menuju endpoint persistence Ticket 67 dan di-gate oleh
        // state `processing` sehingga double-click tidak mengirim request
        // berulang.
        $this->assertStringContainsString("route('shm.loans.pengajuan.store')", $source);
        $this->assertStringContainsString('if (processing) return;', $source);
        $this->assertStringContainsString('disabled={processing}', $source);
        $this->assertStringContainsString('onStart: () => setProcessing(true)', $source);
        $this->assertStringContainsString('onFinish: () => setProcessing(false)', $source);

        // Mutasi lain hanya PUT update mode edit (Ticket 68) — tanpa
        // DELETE/PATCH yang tidak pernah ada endpoint-nya.
        $this->assertStringContainsString("route('shm.loans.pengajuan.update'", $source);
        $this->assertStringNotContainsString('router.delete', $source);
        $this->assertStringNotContainsString('router.patch', $source);
    }
}
