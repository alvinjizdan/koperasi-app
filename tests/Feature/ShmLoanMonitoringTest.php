<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanApplicationAsset;
use App\Models\ShmLoanApplicationCollateral;
use App\Models\ShmLoanProduct;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Ticket 70 — Daftar & Detail Pinjaman SHM aktif (layer MONITORING).
 *
 * Yang diuji:
 * - A. AUTHORIZATION: guest 302, tanpa `shm.view` 403, restricted user hanya
 *   melihat pinjaman dalam lingkup ownership-nya, pemegang `shm.view_all`
 *   melihat seluruhnya. Ownership mengikuti pengajuan asal pencairan karena
 *   `shm_loans` tidak memiliki `marketing_id` sendiri.
 * - B. LIST: search (nomor pinjaman, nama & nomor nasabah), filter status,
 *   sorting whitelist (kolom arbitrer diabaikan), pagination server-side.
 * - C. DETAIL: nasabah dari `shm_customers` (bukan `members`), pengajuan
 *   asal, principal/tenor/rate/diserbursed_at/status sesuai record.
 * - D. INTEGRITY: pinjaman tidak ada = 404, satu pengajuan tidak pernah
 *   menghasilkan pinjaman kedua, perilaku pencairan T69 tidak berubah.
 *
 * Yang TIDAK diuji: formula bunga, jadwal/nominal angsuran, pembayaran,
 * pelunasan, collection, denda, restructuring, write-off — seluruhnya di luar
 * scope T70 (PENDING BUSINESS CONFIRMATION).
 */
class ShmLoanMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $marketing;

    private User $marketing2;

    private User $kasir;

    private ShmLoanProduct $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->admin = $this->userWithRole('admin');
        $this->marketing = $this->userWithRole('marketing');
        $this->marketing2 = $this->userWithRole('marketing');
        $this->kasir = $this->userWithRole('kasir');

        $this->product = ShmLoanProduct::factory()->create();
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', $roleName)->value('id'));

        return $user;
    }

    /**
     * Pengguna dengan permission eksplisit tanpa role bawaan — dipakai untuk
     * memastikan `shm.view_all` (bukan sekadar `shm.view`) yang membuka
     * seluruh data.
     *
     * @param  array<int, string>  $permissionNames
     */
    private function userWithPermissions(array $permissionNames): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $role = Role::query()->create([
            'name' => 'monitor-'.Str::uuid(),
            'display_name' => 'Monitoring Test',
            'is_system' => false,
        ]);

        $role->permissions()->attach(
            Permission::query()->whereIn('name', $permissionNames)->pluck('id')
        );

        $user->roles()->attach($role->id);

        return $user;
    }

    /** Pengajuan Pinjaman SHM milik $marketing (tanpa melewati validasi form). */
    private function makeApplication(
        User $owner,
        ?ShmCustomer $customer = null,
        array $override = [],
    ): ShmLoanApplication {
        static $sequence = 0;

        return ShmLoanApplication::query()->create(array_merge([
            'application_number' => 'APP-T70-'.str_pad((string) ++$sequence, 5, '0', STR_PAD_LEFT),
            'shm_customer_id' => ($customer ?? ShmCustomer::factory()->create())->id,
            'shm_loan_product_id' => $this->product->id,
            'application_date' => now()->toDateString(),
            'principal' => 10_000_000,
            'tenor_months' => 12,
            'status' => 'pending',
            'marketing_id' => $owner->id,
        ], $override));
    }

    /**
     * Pinjaman yang berasal dari pencairan $application (dibuat langsung
     * sehingga test list/detail tidak bergantung pada alur approval penuh).
     */
    private function makeLoan(
        ShmLoanApplication $application,
        array $override = [],
    ): ShmLoan {
        return ShmLoan::factory()->create(array_merge([
            'shm_customer_id' => $application->shm_customer_id,
            'shm_loan_product_id' => $application->shm_loan_product_id,
            'shm_loan_application_id' => $application->id,
            'principal' => $application->principal,
            'tenor_months' => $application->tenor_months,
        ], $override));
    }

    // -----------------------------------------------------------------
    // A. AUTHORIZATION
    // -----------------------------------------------------------------

    public function test_guest_cannot_open_loan_list(): void
    {
        $this->app['auth']->forgetGuards();

        $this->get(route('shm.loans.index'))
            ->assertRedirect(route('shm.login'));
    }

    public function test_guest_cannot_open_loan_detail(): void
    {
        $application = $this->makeApplication($this->marketing);
        $loan = $this->makeLoan($application);

        $this->app['auth']->forgetGuards();

        $this->get(route('shm.loans.show', $loan))
            ->assertRedirect(route('shm.login'));
    }

    public function test_user_without_shm_view_permission_cannot_open_loan_list(): void
    {
        $this->actingAs($this->kasir)
            ->get(route('shm.loans.index'))
            ->assertForbidden();
    }

    public function test_user_without_shm_view_permission_cannot_open_loan_detail(): void
    {
        $application = $this->makeApplication($this->marketing);
        $loan = $this->makeLoan($application);

        $this->actingAs($this->kasir)
            ->get(route('shm.loans.show', $loan))
            ->assertForbidden();
    }

    public function test_restricted_user_only_sees_loans_within_ownership_scope(): void
    {
        $mine = $this->makeLoan($this->makeApplication($this->marketing));
        $this->makeLoan($this->makeApplication($this->marketing2));

        $this->actingAs($this->marketing)
            ->get(route('shm.loans.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Loans/Index')
                ->where('loans.total', 1)
                ->where('loans.data.0.id', $mine->id),
            );
    }

    public function test_restricted_user_cannot_open_detail_of_another_marketing_loan(): void
    {
        $other = $this->makeLoan($this->makeApplication($this->marketing2));

        $this->actingAs($this->marketing)
            ->get(route('shm.loans.show', $other))
            ->assertForbidden();
    }

    public function test_restricted_user_sees_only_own_loans_even_when_several_exist(): void
    {
        $this->makeLoan($this->makeApplication($this->marketing2));
        $this->makeLoan($this->makeApplication($this->marketing2));
        $own = $this->makeLoan($this->makeApplication($this->marketing));

        $response = $this->actingAs($this->marketing)
            ->get(route('shm.loans.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Loans/Index')
                ->where('loans.total', 1)
                ->where('loans.data.0.id', $own->id));

        $this->assertSame(1, $response->original->getData()['page']['props']['loans']['total']);
    }

    public function test_view_all_holder_sees_all_loans(): void
    {
        $this->makeLoan($this->makeApplication($this->marketing));
        $this->makeLoan($this->makeApplication($this->marketing2));

        $this->actingAs($this->admin)
            ->get(route('shm.loans.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Loans/Index')
                ->where('loans.total', 2));
    }

    public function test_view_only_permission_is_not_enough_to_see_other_marketing_loans(): void
    {
        // `shm.view` SAH untuk membuka halaman, tetapi tanpa `shm.view_all`
        // lingkup ownership tetap berlaku.
        $viewer = $this->userWithPermissions(['shm.view']);

        $this->makeLoan($this->makeApplication($this->marketing));
        $this->makeLoan($this->makeApplication($this->marketing2));

        $this->actingAs($viewer)
            ->get(route('shm.loans.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('loans.total', 0));
    }

    public function test_loan_without_origin_application_is_only_visible_to_view_all(): void
    {
        $orphan = ShmLoan::factory()->create();

        $this->actingAs($this->marketing)
            ->get(route('shm.loans.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('loans.total', 0));

        $this->actingAs($this->marketing)
            ->get(route('shm.loans.show', $orphan))
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->get(route('shm.loans.show', $orphan))
            ->assertOk();
    }

    // -----------------------------------------------------------------
    // B. LIST
    // -----------------------------------------------------------------

    public function test_active_loan_appears_in_list(): void
    {
        $application = $this->makeApplication($this->marketing);
        $loan = $this->makeLoan($application, ['status' => ShmLoan::STATUS_ACTIVE]);

        $this->actingAs($this->admin)
            ->get(route('shm.loans.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Loans/Index')
                ->where('loans.total', 1)
                ->where('loans.data.0.id', $loan->id)
                ->where('loans.data.0.status', ShmLoan::STATUS_ACTIVE));
    }

    public function test_search_by_loan_number_works(): void
    {
        $target = $this->makeLoan(
            $this->makeApplication($this->marketing),
            ['loan_number' => 'SHM-T70-TARGET-001'],
        );
        $this->makeLoan(
            $this->makeApplication($this->marketing),
            ['loan_number' => 'SHM-T70-OTHER-999'],
        );

        $this->actingAs($this->admin)
            ->get(route('shm.loans.index', ['search' => 'TARGET-001']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('loans.total', 1)
                ->where('loans.data.0.id', $target->id));
    }

    public function test_search_by_customer_name_works(): void
    {
        $customer = ShmCustomer::factory()->create(['name' => 'Nyai Sekar Ayu']);
        $loan = $this->makeLoan($this->makeApplication($this->marketing, $customer));

        $this->actingAs($this->admin)
            ->get(route('shm.loans.index', ['search' => 'Sekar Ayu']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('loans.total', 1)
                ->where('loans.data.0.id', $loan->id));
    }

    public function test_search_by_customer_number_works(): void
    {
        $customer = ShmCustomer::factory()->create([
            'customer_number' => 'NAS-T70-4242',
        ]);
        $loan = $this->makeLoan($this->makeApplication($this->marketing, $customer));

        $this->actingAs($this->admin)
            ->get(route('shm.loans.index', ['search' => 'NAS-T70-4242']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('loans.total', 1)
                ->where('loans.data.0.id', $loan->id));
    }

    public function test_search_never_matches_cooperative_member(): void
    {
        // Member koperasi dengan nomor yang sama sekali berbeda tidak boleh
        // pernah cocok dengan pinjaman SHM.
        Member::factory()->create(['full_name' => 'Pak Koperasi']);

        $this->makeLoan(
            $this->makeApplication($this->marketing),
            [
                'loan_number' => 'SHM-PIN-991122',
                // Purpose & lokasi dipatok supaya kata 'Koperasi' tidak
                // mungkin muncul dari teks Faker acak.
                'purpose' => 'Modal usaha batik',
                'location' => 'Cirebon',
            ],
        );

        $this->actingAs($this->admin)
            ->get(route('shm.loans.index', ['search' => 'Koperasi']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('loans.total', 0));
    }

    public function test_status_filter_works(): void
    {
        $this->makeLoan($this->makeApplication($this->marketing), ['status' => ShmLoan::STATUS_ACTIVE]);
        $this->makeLoan($this->makeApplication($this->marketing), ['status' => ShmLoan::STATUS_CLOSED]);

        $this->actingAs($this->admin)
            ->get(route('shm.loans.index', ['status' => ShmLoan::STATUS_ACTIVE]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('loans.total', 1)
                ->where('loans.data.0.status', ShmLoan::STATUS_ACTIVE));

        $this->actingAs($this->admin)
            ->get(route('shm.loans.index', ['status' => 'all']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('loans.total', 2));
    }

    public function test_pagination_is_server_side_and_keeps_filters(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->makeLoan($this->makeApplication($this->marketing));
        }

        $response = $this->actingAs($this->admin)
            ->get(route('shm.loans.index', ['per_page' => 10, 'status' => ShmLoan::STATUS_ACTIVE]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('loans.total', 12)
                ->where('loans.per_page', 10)
                ->has('loans.data', 10));

        // withQueryString(): filter & pencarian ikut terbawa pada URL halaman
        // berikutnya — tercermin pada `next_page_url` paginator.
        $next = $response->original->getData()['page']['props']['loans']['next_page_url'];

        $this->assertStringContainsString('per_page=10', $next);
        $this->assertStringContainsString('status=active', $next);

        $this->actingAs($this->admin)
            ->get(route('shm.loans.index', ['per_page' => 10, 'page' => 2, 'status' => ShmLoan::STATUS_ACTIVE]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('loans.current_page', 2)
                ->has('loans.data', 2));
    }

    public function test_sorting_uses_whitelist_and_ignores_arbitrary_columns(): void
    {
        $this->makeLoan($this->makeApplication($this->marketing));

        // Kolom yang tidak ada di whitelist tidak boleh diterapkan dan tidak
        // boleh membuat error — status sorting kembali null (fallback).
        $this->actingAs($this->admin)
            ->get(route('shm.loans.index', ['sort' => 'shm_customer_id', 'direction' => 'desc']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('sort.key', null));

        $this->actingAs($this->admin)
            ->get(route('shm.loans.index', ['sort' => '(select 1)', 'direction' => 'asc']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('sort.key', null));
    }

    public function test_sorting_by_allowed_column_is_applied(): void
    {
        $first = $this->makeLoan($this->makeApplication($this->marketing), [
            'loan_number' => 'SHM-SORT-A',
            'principal' => 5_000_000,
        ]);
        $second = $this->makeLoan($this->makeApplication($this->marketing), [
            'loan_number' => 'SHM-SORT-Z',
            'principal' => 90_000_000,
        ]);

        $this->actingAs($this->admin)
            ->get(route('shm.loans.index', ['sort' => 'principal', 'direction' => 'asc']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('sort.key', 'principal')
                ->where('sort.direction', 'asc')
                ->where('loans.data.0.id', $first->id)
                ->where('loans.data.1.id', $second->id));
    }

    public function test_list_data_comes_from_shm_loans_not_applications(): void
    {
        $application = $this->makeApplication($this->marketing, null, ['principal' => 77_000_000]);
        $loan = $this->makeLoan($application, ['principal' => 33_000_000]);

        $this->actingAs($this->admin)
            ->get(route('shm.loans.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('loans.total', 1)
                ->where('loans.data.0.id', $loan->id)
                ->where('loans.data.0.principal', '33000000.00'));
    }

    // -----------------------------------------------------------------
    // C. DETAIL
    // -----------------------------------------------------------------

    public function test_loan_detail_page_opens(): void
    {
        $application = $this->makeApplication($this->marketing);
        $loan = $this->makeLoan($application);

        $this->actingAs($this->admin)
            ->get(route('shm.loans.show', $loan))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Loans/Show')
                ->where('loan.id', $loan->id)
                ->where('loan.loan_number', $loan->loan_number));
    }

    public function test_detail_shows_shm_customer_not_cooperative_member(): void
    {
        $member = Member::factory()->create(['full_name' => 'Bukan Nasabah SHM']);
        $customer = ShmCustomer::factory()->create([
            'name' => 'Nasabah SHM Sah',
            'customer_number' => 'NAS-SHM-001',
            'nik' => '3273019900010001',
            'phone' => '08123456789',
            'address' => 'Jl. Cirebon No. 1',
        ]);

        $loan = $this->makeLoan($this->makeApplication($this->marketing, $customer));

        $response = $this->actingAs($this->admin)
            ->get(route('shm.loans.show', $loan))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Loans/Show')
                ->where('loan.customer.name', 'Nasabah SHM Sah')
                ->where('loan.customer.customer_number', 'NAS-SHM-001')
                ->where('loan.customer.nik', '3273019900010001')
                ->where('loan.customer.phone', '08123456789')
                ->where('loan.customer.address', 'Jl. Cirebon No. 1'));

        $props = $response->original->getData()['page']['props'];

        // Customer yang dirender adalah baris shm_customers — bukan member.
        $this->assertSame(
            $customer->id,
            $props['loan']['customer']['id'],
        );
        $this->assertNotSame(
            $member->full_name,
            $props['loan']['customer']['name'],
        );
        $this->assertStringNotContainsString('Bukan Nasabah SHM', $response->getContent());
    }

    public function test_detail_shows_origin_application(): void
    {
        $application = $this->makeApplication($this->marketing, null, [
            'application_date' => '2026-01-15',
            'purpose' => 'Modal usaha batik',
            'principal' => 25_000_000,
            'tenor_months' => 24,
        ]);
        $loan = $this->makeLoan($application);

        $this->actingAs($this->admin)
            ->get(route('shm.loans.show', $loan))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Loans/Show')
                ->where('loan.application.id', $application->id)
                ->where('loan.application.application_number', $application->application_number)
                ->where('loan.application.application_date', '2026-01-15')
                ->where('loan.application.purpose', 'Modal usaha batik')
                ->where('loan.application.principal', '25000000.00')
                ->where('loan.application.tenor_months', 24));
    }

    public function test_detail_shows_correct_principal(): void
    {
        $loan = $this->makeLoan(
            $this->makeApplication($this->marketing),
            ['principal' => 46_500_000],
        );

        $this->actingAs($this->admin)
            ->get(route('shm.loans.show', $loan))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('loan.principal', '46500000.00'));
    }

    public function test_detail_shows_correct_tenor(): void
    {
        $loan = $this->makeLoan(
            $this->makeApplication($this->marketing),
            ['tenor_months' => 36],
        );

        $this->actingAs($this->admin)
            ->get(route('shm.loans.show', $loan))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('loan.tenor_months', 36));
    }

    public function test_detail_shows_official_rate_without_assuming_period_or_formula(): void
    {
        $loan = $this->makeLoan($this->makeApplication($this->marketing), [
            'rate_percent' => ShmLoan::OFFICIAL_RATE_PERCENT,
            'rate_period' => null,
            'calculation_method' => null,
        ]);

        $this->actingAs($this->admin)
            ->get(route('shm.loans.show', $loan))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('loan.rate_percent', '2.0000')
                // Periode & metode TIDAK boleh ditebak sistem.
                ->where('loan.rate_period', null)
                ->where('loan.calculation_method', null)
                // Tanpa metode + periode eksplisit, tidak ada jadwal angsuran.
                ->where('result', null));
    }

    public function test_detail_shows_disbursement_information(): void
    {
        $loan = $this->makeLoan($this->makeApplication($this->marketing), [
            'disbursed_at' => '2026-03-05',
            'principal' => 15_000_000,
        ]);

        $this->actingAs($this->admin)
            ->get(route('shm.loans.show', $loan))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('loan.disbursement.disbursed_at', '2026-03-05')
                ->where('loan.disbursement.loan_number', $loan->loan_number)
                ->where('loan.disbursement.principal', '15000000.00')
                ->where('loan.disbursement.status', ShmLoan::STATUS_ACTIVE));
    }

    public function test_detail_shows_correct_status(): void
    {
        $active = $this->makeLoan($this->makeApplication($this->marketing), ['status' => ShmLoan::STATUS_ACTIVE]);
        $closed = $this->makeLoan($this->makeApplication($this->marketing), ['status' => ShmLoan::STATUS_CLOSED]);

        $this->actingAs($this->admin)
            ->get(route('shm.loans.show', $active))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('loan.status', ShmLoan::STATUS_ACTIVE));

        $this->actingAs($this->admin)
            ->get(route('shm.loans.show', $closed))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('loan.status', ShmLoan::STATUS_CLOSED));
    }

    public function test_detail_shows_collateral_and_asset_from_origin_application(): void
    {
        $application = $this->makeApplication($this->marketing);

        ShmLoanApplicationCollateral::query()->create([
            'shm_loan_application_id' => $application->id,
            'collateral_type' => 'SHM',
            'collateral_name' => 'Ruko Harjamukti',
            'estimated_value' => 500_000_000,
            'binding_status' => 'belum',
        ]);

        ShmLoanApplicationAsset::query()->create([
            'shm_loan_application_id' => $application->id,
            'asset_type' => 'Kendaraan',
            'asset_number' => 'E 1122 ABD',
            'asset_year' => '2021',
            'estimated_value' => 120_000_000,
        ]);

        $loan = $this->makeLoan($application);

        $this->actingAs($this->admin)
            ->get(route('shm.loans.show', $loan))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Loans/Show')
                ->has('loan.jaminan', 1)
                ->where('loan.jaminan.0.jenis', 'SHM')
                ->where('loan.jaminan.0.nama', 'Ruko Harjamukti')
                ->where('loan.jaminan.0.nilai_perkiraan', '500000000.00')
                ->has('loan.agunan', 1)
                ->where('loan.agunan.0.jenis', 'Kendaraan')
                ->where('loan.agunan.0.nomor', 'E 1122 ABD')
                ->where('loan.agunan.0.nilai', '120000000.00'));
    }

    public function test_detail_of_loan_without_application_shows_empty_sections(): void
    {
        $loan = ShmLoan::factory()->create(['shm_loan_application_id' => null]);

        $this->actingAs($this->admin)
            ->get(route('shm.loans.show', $loan))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shm/Loans/Show')
                ->where('loan.application', null)
                ->has('loan.jaminan', 0)
                ->has('loan.agunan', 0)
                ->has('loan.timeline', 0));
    }

    // -----------------------------------------------------------------
    // D. INTEGRITY
    // -----------------------------------------------------------------

    public function test_missing_loan_returns_not_found(): void
    {
        $this->actingAs($this->admin)
            ->get(route('shm.loans.show', 999_999))
            ->assertNotFound();
    }

    public function test_list_of_missing_search_term_returns_empty_page(): void
    {
        $this->makeLoan($this->makeApplication($this->marketing));

        $this->actingAs($this->admin)
            ->get(route('shm.loans.index', ['search' => 'TIDAK-ADA-SAMA-SEKALI']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('loans.total', 0)
                ->has('loans.data', 0));
    }

    public function test_one_application_still_cannot_produce_a_second_loan(): void
    {
        $application = $this->makeApprovedApplication();

        $this->disburse($application)->assertSessionHasNoErrors();
        $this->assertSame(1, ShmLoan::query()->count());

        // Percobaan kedua ditolak service (guard idempotency T69) — T70 tidak
        // membuka celah loan ganda.
        $this->disburse($application)->assertSessionHasErrors();

        $this->assertSame(1, ShmLoan::query()->count());
    }

    public function test_disbursement_behaviour_is_unchanged_by_ticket_70(): void
    {
        $application = $this->makeApprovedApplication();

        $this->disburse($application)->assertSessionHasNoErrors();

        $loan = ShmLoan::query()->sole();

        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->status);
        $this->assertNotNull($loan->disbursed_at);
        $this->assertSame($application->id, $loan->shm_loan_application_id);
        $this->assertSame($application->shm_customer_id, $loan->shm_customer_id);
        $this->assertSame($this->product->id, $loan->shm_loan_product_id);
    }

    public function test_timeline_reads_existing_audit_events_only(): void
    {
        $application = $this->makeApprovedApplication();
        $this->disburse($application)->assertSessionHasNoErrors();

        $loan = ShmLoan::query()->sole();

        $response = $this->actingAs($this->admin)
            ->get(route('shm.loans.show', $loan))
            ->assertOk();

        $timeline = $response->original->getData()['page']['props']['loan']['timeline'];

        // Hanya peristiwa audit NYATA yang tampil — tanpa event karangan.
        $this->assertNotEmpty($timeline);
        $this->assertContains(
            'shm.loan_application.disbursed',
            array_column($timeline, 'action'),
        );

        foreach ($timeline as $entry) {
            $this->assertNotEmpty($entry['action']);
        }
    }

    // -----------------------------------------------------------------
    // Fixture pencairan (alur T69 yang sebenarnya)
    // -----------------------------------------------------------------

    /** @return array<string, mixed> */
    private function validPayload(int $customerId): array
    {
        return [
            'pribadi' => ['customer_id' => $customerId],
            'pengajuan' => [
                'product_id' => $this->product->id,
                'principal' => '10000000',
                'tenor_months' => '12',
                'purpose' => 'Modal usaha',
                'loan_sequence' => '',
                'disbursement_method' => '',
            ],
            'income' => [
                'tetap' => '3000000',
                'pasangan' => '0',
                'usaha' => '1000000',
            ],
            'expense' => [
                'potongan' => '0',
                'rumahTangga' => '1000000',
                'pendidikan' => '0',
                'sewa' => '0',
                'listrik' => '0',
                'transportasi' => '0',
                'lain' => '0',
            ],
            'tambahan' => [
                'koperasi_loan_balance' => '0',
                'other_facility' => '',
                'institution_name' => '',
            ],
            'agunan' => [],
            'jaminan' => [],
            'catatan' => ['date' => '', 'name' => '', 'notes' => ''],
            'analisa' => [
                'analyst_code' => '',
                'analyst_office' => '',
                'officer_code' => '',
                'officer_office' => '',
                'date' => '',
                'value' => '',
                'notes' => '',
            ],
            'kolektor' => ['name' => ''],
            'persetujuan' => [
                'approved_amount' => '',
                'provision_fee' => '',
                'administration_fee' => '',
                'method' => '',
                'notes' => '',
            ],
            'persyaratan' => [],
        ];
    }

    private function makeApprovedApplication(): ShmLoanApplication
    {
        $customer = ShmCustomer::factory()->create();

        $this->actingAs($this->marketing)
            ->post(route('shm.loans.pengajuan.store'), $this->validPayload($customer->id))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $application = ShmLoanApplication::query()->latest('id')->firstOrFail();

        $this->actingAs($this->marketing)
            ->post(route('shm.loans.pengajuan.submit-approval', $application))
            ->assertSessionHasNoErrors();

        $mirrorId = $application->refresh()->shm_application_id;

        $this->actingAs($this->admin)
            ->post(route('shm.applications.approve', $mirrorId), ['notes' => 'Keputusan uji T70'])
            ->assertSessionHasNoErrors();

        return $application->refresh();
    }

    private function disburse(ShmLoanApplication $application): TestResponse
    {
        return $this->actingAs($this->admin)
            ->post(route('shm.loans.pengajuan.disburse', $application));
    }
}
