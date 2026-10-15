<?php

namespace Tests\Feature;

use App\Models\Loan;
use App\Models\Member;
use App\Models\Role;
use App\Models\SavingProduct;
use App\Models\SavingsAccount;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanProduct;
use App\Models\User;
use App\Services\Shm\ShmLoanSimulationService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Ticket 64/65 — Modul finansial SHM: Pinjaman, Simulasi & PDF.
 *
 * Yang diuji:
 * - domain SHM terpisah dari domain koperasi (tabel, relasi, master);
 * - default legacy 2% (factory & sandbox simulasi) tidak mengubah rate
 *   koperasi; rate resmi pinjaman = f(produk, tenor) dari matriks produk (T82);
 * - halaman simulasi terbuka bagi pemegang permission shm.view existing dan
 *   ditolak bagi pengguna tanpa permission tersebut;
 * - input simulasi divalidasi (metode & periode wajib, tidak ditebak);
 * - tabel hasil simulasi berstruktur No/Pokok/Jasa/Total/Saldo;
 * - export PDF terlindungi dan menghasilkan file PDF yang valid.
 *
 * Modul Simpanan SHM dihapus pada Ticket 65 (tidak diperlukan); test yang
 * khusus memverifikasi Simpanan SHM ikut dihapus.
 *
 * Yang TIDAK diuji di sini: nilai hasil perhitungan sebuah formula (masih
 * PENDING BUSINESS CONFIRMATION), maupun pembuatan record pinjaman
 * lewat UI (workflow penulisan juga masih PENDING).
 */
class ShmFinancialTest extends TestCase
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

    /** @return array<string, mixed> */
    private function validSimulation(): array
    {
        return [
            'run' => 1,
            'name' => 'Budi Simulasi',
            'principal' => 24_000_000,
            'tenor_months' => 6,
            'rate_percent' => 2,
            'method' => 'flat',
            'rate_period' => 'yearly',
            'location' => 'Cirebon',
            'purpose' => 'Modal usaha',
            'fees' => [
                'administration' => 100_000,
                'provision' => 0,
                'insurance' => 0,
                'other' => 0,
                'block_funds' => 250_000,
            ],
        ];
    }

    private function simulationPage(): TestResponse
    {
        return $this->actingAs($this->admin)
            ->get(route('shm.loans.simulation', $this->validSimulation()));
    }

    // -----------------------------------------------------------------
    // A. Domain boundary — SHM financial terpisah dari koperasi
    // -----------------------------------------------------------------

    public function test_loan_module_uses_shm_domain_tables(): void
    {
        $loan = ShmLoan::factory()->create();

        $this->assertInstanceOf(ShmCustomer::class, $loan->customer);
        $this->assertNull($loan->getAttribute('member_id'));
        $this->assertFalse(Schema::hasColumn('shm_loans', 'member_id'));

        // Pinjaman & angsuran koperasi tidak tersentuh.
        $this->assertSame(0, Loan::query()->count());
    }

    public function test_shm_customer_can_own_shm_financial_data(): void
    {
        $customer = ShmCustomer::factory()->create();

        ShmLoan::factory()->count(3)->create([
            'shm_customer_id' => $customer->id,
        ]);

        $this->assertSame(3, $customer->loans()->count());
    }

    public function test_koperasi_member_financial_data_is_never_mixed_into_shm(): void
    {
        $member = Member::factory()->create();
        SavingsAccount::factory()->create(['member_id' => $member->id]);
        Loan::factory()->create(['member_id' => $member->id]);

        // Data koperasi ada, tetapi tidak pernah muncul di domain SHM.
        $this->assertSame(1, SavingsAccount::query()->count());
        $this->assertSame(1, Loan::query()->count());
        $this->assertSame(0, ShmLoan::query()->count());
        $this->assertSame(0, ShmCustomer::query()->count());
    }

    public function test_shm_masters_are_separate_from_koperasi_masters(): void
    {
        SavingProduct::factory()->create();
        ShmLoanProduct::factory()->create();

        $this->assertSame(1, SavingProduct::query()->count());
        $this->assertSame(1, ShmLoanProduct::query()->count());

        $this->assertTrue(Schema::hasTable('shm_loan_products'));
        $this->assertFalse(Schema::hasColumn('shm_loan_products', 'interest_rate'));
    }

    // -----------------------------------------------------------------
    // B. Default legacy 2% (factory & sandbox simulasi) — rate resmi
    //    produk = f(produk, tenor) dari matriks produk (T82).
    // -----------------------------------------------------------------

    public function test_new_shm_loan_defaults_to_official_rate_two_percent(): void
    {
        $loan = ShmLoan::factory()->create();

        $this->assertSame(2.0, (float) $loan->rate_percent);
        $this->assertSame(2.0, ShmLoanSimulationService::officialRate());
    }

    public function test_simulation_uses_two_percent_when_rate_input_is_omitted(): void
    {
        $params = $this->validSimulation();
        unset($params['rate_percent']);

        $this->actingAs($this->admin)
            ->get(route('shm.loans.simulation', $params))
            ->assertOk()
            ->assertInertia(function (Assert $page): bool {
                $page->component('Shm/Loans/Simulation')
                    ->where('rate', fn (mixed $value): bool => (float) $value === 2.0)
                    ->etc();

                $this->assertSame(2.0, (float) $page->toArray()['props']['result']['rate_percent']);

                return true;
            });
    }

    public function test_rate_two_percent_does_not_change_koperasi_rate(): void
    {
        // Konfigurasi bunga koperasi tetap provisional 0% — SHM tidak menulis ke sana.
        $this->assertSame(0.0, (float) config('business-rules.loan.default_annual_rate'));
        $this->assertSame('annual', config('business-rules.loan.rate_basis'));

        $koperasiLoan = Loan::factory()->create()->refresh();
        $this->assertSame(0.0, (float) $koperasiLoan->interest_rate_annual);
        $this->assertSame('none', $koperasiLoan->interest_method);
    }

    // -----------------------------------------------------------------
    // C. Otorisasi
    // -----------------------------------------------------------------

    public function test_authorized_users_can_open_all_shm_financial_pages(): void
    {
        // Ticket 70 — kepemilikan pinjaman mengikuti pengajuan asal
        // pencairannya, sehingga fixture dibuat memiliki pemilik (marketing)
        // agar ketiga user yang diuji berada dalam lingkup yang sah.
        $application = ShmLoanApplication::query()->create([
            'application_number' => 'APP-FIN-0001',
            'shm_customer_id' => ShmCustomer::factory()->create()->id,
            'shm_loan_product_id' => ShmLoanProduct::factory()->create()->id,
            'application_date' => now()->toDateString(),
            'principal' => 10_000_000,
            'tenor_months' => 12,
            'status' => 'pending',
            'marketing_id' => $this->marketing->id,
        ]);

        $loan = ShmLoan::factory()->create([
            'shm_loan_application_id' => $application->id,
            'shm_customer_id' => $application->shm_customer_id,
            'shm_loan_product_id' => $application->shm_loan_product_id,
        ]);

        $pages = [
            ['shm.loans.products.index', []],
            ['shm.loans.index', []],
            ['shm.loans.show', ['loan' => $loan->id]],
            ['shm.loans.simulation', []],
        ];

        foreach ([$this->admin, $this->superadmin, $this->marketing] as $user) {
            foreach ($pages as [$name, $params]) {
                $this->actingAs($user)
                    ->get(route($name, $params))
                    ->assertOk();
            }
        }
    }

    public function test_user_without_shm_permission_is_denied(): void
    {
        $loan = ShmLoan::factory()->create();

        $this->assertFalse($this->kasir->can('shm.view'));

        $this->actingAs($this->kasir)->get(route('shm.loans.index'))->assertForbidden();
        $this->actingAs($this->kasir)->get(route('shm.loans.products.index'))->assertForbidden();
        $this->actingAs($this->kasir)->get(route('shm.loans.show', $loan->id))->assertForbidden();
        $this->actingAs($this->kasir)->get(route('shm.loans.simulation'))->assertForbidden();
        $this->actingAs($this->kasir)
            ->get(route('shm.loans.simulation.pdf', $this->validSimulation()))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_shm_entry_point(): void
    {
        $this->get(route('shm.loans.simulation'))->assertRedirect(route('shm.login'));
        $this->get(route('shm.loans.simulation.pdf', $this->validSimulation()))
            ->assertRedirect(route('shm.login'));
    }

    public function test_financial_routes_are_registered_with_shm_prefix(): void
    {
        foreach ([
            'shm.loans.products.index',
            'shm.loans.index',
            'shm.loans.show',
            'shm.loans.simulation',
            'shm.loans.simulation.pdf',
        ] as $name) {
            $this->assertTrue(Route::has($name), "Route {$name} belum terdaftar.");
        }

        $this->assertSame(
            '/shm/pinjaman/simulasi',
            parse_url(route('shm.loans.simulation'), PHP_URL_PATH),
        );
    }

    // -----------------------------------------------------------------
    // D. Validasi input simulasi
    // -----------------------------------------------------------------

    public function test_simulation_requires_explicit_method_and_rate_period(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('shm.loans.simulation', [
                'run' => 1,
                'principal' => 10_000_000,
                'tenor_months' => 12,
            ]));

        $response->assertRedirect();
        $response->assertSessionHasErrors(['method', 'rate_period']);
    }

    public function test_simulation_validates_numeric_inputs(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('shm.loans.simulation', [
                'run' => 1,
                'principal' => 0,
                'tenor_months' => 0,
                'rate_percent' => -1,
                'method' => 'flat',
                'rate_period' => 'yearly',
            ]));

        $response->assertRedirect();
        $response->assertSessionHasErrors(['principal', 'tenor_months', 'rate_percent']);
    }

    public function test_simulation_rejects_unknown_method_and_period_instead_of_guessing(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('shm.loans.simulation', [
                'run' => 1,
                'principal' => 10_000_000,
                'tenor_months' => 12,
                'method' => 'tanpa-bunga-khusus',
                'rate_period' => 'tahunan',
            ]));

        $response->assertRedirect();
        $response->assertSessionHasErrors(['method', 'rate_period']);
    }

    public function test_simulation_page_without_input_shows_empty_form(): void
    {
        $this->actingAs($this->admin)
            ->get(route('shm.loans.simulation'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Loans/Simulation')
                ->where('result', null)
                ->where('rate', fn (mixed $value): bool => (float) $value === 2.0)
                ->etc());
    }

    // -----------------------------------------------------------------
    // E. Struktur hasil simulasi
    // -----------------------------------------------------------------

    public function test_simulation_result_has_installment_table_structure(): void
    {
        $this->simulationPage()
            ->assertOk()
            ->assertInertia(function (Assert $page): bool {
                $page->component('Shm/Loans/Simulation')
                    ->where('result.rate_percent', fn (mixed $value): bool => (float) $value === 2.0)
                    ->where('result.tenor_months', 6)
                    ->where('result.method', 'flat')
                    ->where('result.rate_period', 'yearly')
                    ->has('result.rows', 6)
                    ->etc();

                $rows = $page->toArray()['props']['result']['rows'];

                foreach ($rows as $index => $row) {
                    $this->assertSame(
                        ['period', 'principal', 'interest', 'total', 'balance'],
                        array_keys($row),
                        'Struktur kolom tabel angsuran berubah.',
                    );
                    $this->assertSame($index + 1, $row['period']);
                }

                $this->assertEqualsWithDelta(24_000_000, array_sum(array_column($rows, 'principal')), 0.01);
                $this->assertSame(0.0, (float) end($rows)['balance']);

                return true;
            });
    }

    public function test_simulation_result_exposes_manual_costs_and_pending_penalty(): void
    {
        $this->simulationPage()
            ->assertOk()
            ->assertInertia(function (Assert $page): bool {
                $page->component('Shm/Loans/Simulation')->etc();

                $result = $page->toArray()['props']['result'];

                // Biaya berasal dari input manual, bukan formula.
                $this->assertEqualsWithDelta(100000, $result['total_fees'], 0.01);
                $this->assertEqualsWithDelta(250000, $result['block_funds'], 0.01);
                // Blokir dana tidak dipotong dari pencairan.
                $this->assertEqualsWithDelta(23900000, $result['net_disbursement'], 0.01);

                return true;
            });
    }

    public function test_simulation_page_lists_shm_loan_products_and_explicit_options(): void
    {
        $product = ShmLoanProduct::factory()->create(['name' => 'Produk Uji SHM']);

        $this->simulationPage()
            ->assertOk()
            ->assertInertia(function (Assert $page): bool {
                $page->component('Shm/Loans/Simulation')
                    ->has('products', 1)
                    ->where('products.0.name', 'Produk Uji SHM')
                    ->where('options.methods', ['flat' => 'Bunga flat (dihitung dari pokok awal)', 'effective' => 'Bunga efektif (saldo pokok menurun)', 'annuity' => 'Anuitas (angsuran tetap)'])
                    ->where('options.periods', ['yearly' => 'Per tahun', 'monthly' => 'Per bulan'])
                    ->etc();

                return true;
            });
    }

    public function test_loan_detail_shows_schedule_only_when_method_and_period_are_explicit(): void
    {
        $openEnded = ShmLoan::factory()->create([
            'calculation_method' => null,
            'rate_period' => null,
        ]);

        $explicit = ShmLoan::factory()->create([
            'calculation_method' => 'flat',
            'rate_period' => 'yearly',
            'tenor_months' => 4,
        ]);

        $this->actingAs($this->admin)
            ->get(route('shm.loans.show', $openEnded->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Loans/Show')
                ->where('result', null)
                ->where('loan.rate_percent', '2.0000')
                ->etc());

        $this->actingAs($this->admin)
            ->get(route('shm.loans.show', $explicit->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Loans/Show')
                ->has('result.rows', 4)
                ->etc());
    }

    // -----------------------------------------------------------------
    // F. Export PDF
    // -----------------------------------------------------------------

    public function test_pdf_export_returns_a_valid_pdf_file(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('shm.loans.simulation.pdf', $this->validSimulation()));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');

        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertMatchesRegularExpression(
            '/attachment; filename="simulasi-pinjaman-[0-9a-f]{16}\.pdf"/',
            $disposition,
        );

        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringStartsWith('%PDF-', $content);
        $this->assertStringContainsString('%%EOF', $content);
        $this->assertStringContainsString('startxref', $content);

        // Judul dokumen berasal dari halaman simulasi yang sedang ditampilkan.
        $this->assertStringContainsString(
            mb_convert_encoding('Simulasi Pinjaman SHM', 'UTF-16BE'),
            $content,
        );

        // Dokumen benar-benar memuat halaman (bukan file PDF kosong).
        $this->assertMatchesRegularExpression('/\/Type \/Pages/', $content);
        $this->assertMatchesRegularExpression('/\/Count [1-9]/', $content);
    }

    public function test_pdf_grows_with_the_installment_schedule(): void
    {
        $short = $this->actingAs($this->admin)->get(route('shm.loans.simulation.pdf', [
            'run' => 1,
            'principal' => 1_000_000,
            'tenor_months' => 3,
            'rate_percent' => 2,
            'method' => 'flat',
            'rate_period' => 'yearly',
        ]));

        $long = $this->actingAs($this->admin)->get(route('shm.loans.simulation.pdf', [
            'run' => 1,
            'principal' => 1_000_000,
            'tenor_months' => 240,
            'rate_percent' => 2,
            'method' => 'flat',
            'rate_period' => 'yearly',
        ]));

        $short->assertOk();
        $long->assertOk();

        // Tabel angsuran yang lebih panjang menghasilkan dokumen yang lebih
        // besar — membuktikan baris jadwal benar-benar dirender ke PDF.
        $this->assertGreaterThan(
            strlen((string) $short->getContent()),
            strlen((string) $long->getContent()),
        );
    }

    public function test_pdf_export_filename_is_deterministic_for_same_input(): void
    {
        $first = $this->actingAs($this->admin)
            ->get(route('shm.loans.simulation.pdf', $this->validSimulation()))
            ->headers->get('Content-Disposition');

        $second = $this->actingAs($this->admin)
            ->get(route('shm.loans.simulation.pdf', $this->validSimulation()))
            ->headers->get('Content-Disposition');

        $this->assertSame($first, $second);

        $changedInput = $this->validSimulation();
        $changedInput['principal'] = 30_000_000;

        $third = $this->actingAs($this->admin)
            ->get(route('shm.loans.simulation.pdf', $changedInput))
            ->headers->get('Content-Disposition');

        $this->assertNotSame($first, $third);
    }

    public function test_pdf_export_without_simulation_input_redirects_to_simulation_page(): void
    {
        $this->actingAs($this->admin)
            ->get(route('shm.loans.simulation.pdf'))
            ->assertRedirect(route('shm.loans.simulation'));
    }
}
