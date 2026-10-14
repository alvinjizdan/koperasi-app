<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Member;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanInstallment;
use App\Models\ShmLoanPayment;
use App\Models\ShmLoanPaymentAllocation;
use App\Models\User;
use App\Services\Shm\ShmLoanPaymentAllocationService;
use App\Services\Shm\ShmLoanPaymentService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ticket 90 — Collection monitoring & work queue (READ-ONLY).
 *
 * Mengunci: otorisasi server-side (permission + ownership + view_all),
 * kelayakan Collection (active + disbursed + tunggakan aktual; closed /
 * belum cair / lunas seluruhnya TIDAK muncul), akurasi perhitungan
 * (earliest overdue, days overdue, jumlah angsuran, sisa pokok/bunga/total),
 * refleksi pembayaran parsial dari data alokasi aktual, query server-side
 * (search / filter / sorting whitelist / pagination), konten detail
 * (nasabah + anggota terkait, pinjaman, ringkasan, jadwal dengan
 * presentation state derived, riwayat pembayaran, timeline audit), serta
 * BATAS keras read-only: GET tidak pernah mengubah data maupun audit.
 *
 * Tidak ada pengujian penalty/collector/escalation/cadence karena rule
 * tersebut masih PENDING dan sengaja tidak diimplementasikan.
 */
class ShmCollectionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $marketing;

    private User $marketing2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        // admin = pemegang shm.view_all; marketing tanpa view_all.
        $this->admin = $this->userWithRole('admin');
        $this->marketing = $this->userWithRole('marketing');
        $this->marketing2 = $this->userWithRole('marketing');
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
            'name' => 'collection-tester-'.Str::uuid(),
            'display_name' => 'Collection Tester',
            'is_system' => false,
        ]);

        $role->permissions()->attach(
            Permission::query()->whereIn('name', $permissionNames)->pluck('id')
        );

        $user->roles()->attach($role->id);

        return $user;
    }

    private function applicationOwnedBy(User $user, ?string $customerName = null): ShmLoanApplication
    {
        $customer = ShmCustomer::factory()->create(
            $customerName !== null ? ['name' => $customerName] : [],
        );

        return ShmLoanApplication::query()->create([
            'application_number' => 'PENGAJ-PIN-'.Str::upper(Str::random(10)),
            'shm_customer_id' => $customer->id,
            'application_date' => now()->toDateString(),
            'principal' => '100000000',
            'tenor_months' => 12,
            'first_due_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'marketing_id' => $user->id,
            'status' => 'approved',
        ]);
    }

    private function loanForApplication(ShmLoanApplication $application): ShmLoan
    {
        return ShmLoan::factory()->create([
            'shm_customer_id' => $application->shm_customer_id,
            'shm_loan_application_id' => $application->id,
            'principal' => $application->principal,
            'tenor_months' => $application->tenor_months,
            'first_due_date' => $application->first_due_date,
        ]);
    }

    /**
     * Pinjaman aktif + sudah cair (default factory) milik $owner dengan
     * satu angsuran tunggakan berumur $daysOverdue hari.
     */
    private function overdueLoanOf(User $owner, ?string $customerName = null, int $daysOverdue = 30): ShmLoan
    {
        $application = $this->applicationOwnedBy($owner, $customerName);
        $loan = $this->loanForApplication($application);

        $this->scheduleOf($loan, [
            ['due_date' => today()->subDays($daysOverdue)->toDateString()],
        ]);

        return $loan;
    }

    /**
     * Pinjaman tunggakan tanpa pengajuan asal — hanya terlihat pemegang
     * shm.view_all (dipakai fixture di luar pengujian ownership).
     */
    private function plainOverdueLoan(int $daysOverdue = 30, ?string $customerName = null): ShmLoan
    {
        $attributes = [];

        if ($customerName !== null) {
            $attributes['shm_customer_id'] = ShmCustomer::factory()
                ->create(['name' => $customerName])->id;
        }

        $loan = ShmLoan::factory()->create($attributes);

        $this->scheduleOf($loan, [
            ['due_date' => today()->subDays($daysOverdue)->toDateString()],
        ]);

        return $loan;
    }

    /**
     * Buat baris jadwal angsuran persis seperti hasil ShmRepaymentScheduleService
     * (semua kolom pelacakan pembayaran diisi 0 / sisa penuh).
     *
     * @param  array<int, array{due_date: string, principal?: float|int|string, interest?: float|int|string}>  $specs
     */
    private function scheduleOf(ShmLoan $loan, array $specs): void
    {
        foreach ($specs as $index => $spec) {
            $principal = (string) ($spec['principal'] ?? 1_000_000);
            $interest = (string) ($spec['interest'] ?? 200_000);
            $total = (string) ((float) $principal + (float) $interest);

            $loan->installments()->create([
                'installment_number' => $index + 1,
                'due_date' => $spec['due_date'],
                'principal_amount' => $principal,
                'interest_amount' => $interest,
                'total_amount' => $total,
                'paid_amount' => 0,
                'paid_interest_amount' => 0,
                'paid_principal_amount' => 0,
                'remaining_amount' => $total,
                'status' => ShmLoanInstallment::STATUS_SCHEDULED,
            ]);
        }
    }

    private function paymentFor(ShmLoan $loan, float $amount, User $as): ShmLoanPayment
    {
        return app(ShmLoanPaymentService::class)->create($loan, [
            'payment_date' => now()->toDateString(),
            'amount' => $amount,
        ], (int) $as->id);
    }

    /**
     * Alokasikan pembayaran memakai service finansial ASLI (urutan bunga →
     * pokok), sehingga fixture paid split sama dengan produksi.
     */
    private function allocate(ShmLoanPayment $payment): void
    {
        app(ShmLoanPaymentAllocationService::class)->allocate($payment);
    }

    // ------------------------------------------------------------------
    // A. Authorization
    // ------------------------------------------------------------------

    public function test_user_with_shm_view_can_access_collection(): void
    {
        $this->overdueLoanOf($this->marketing);

        $this->actingAs($this->marketing)
            ->get(route('shm.collection.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Shm/Collection/Index')
                ->has('loans.data', 1));
    }

    public function test_user_without_shm_view_is_rejected(): void
    {
        $user = $this->userWithPermissions(['dashboard.view']);

        $this->actingAs($user)
            ->get(route('shm.collection.index'))
            ->assertForbidden();

        $loan = $this->plainOverdueLoan();

        $this->actingAs($user)
            ->get(route('shm.collection.show', $loan))
            ->assertForbidden();
    }

    // ------------------------------------------------------------------
    // B. Eligibility
    // ------------------------------------------------------------------

    public function test_active_disbursed_loan_with_overdue_installment_appears(): void
    {
        $this->plainOverdueLoan(daysOverdue: 10);

        $this->actingAs($this->admin)
            ->get(route('shm.collection.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Shm/Collection/Index')
                ->has('loans.data', 1));
    }

    public function test_active_loan_with_future_installment_only_does_not_appear(): void
    {
        $loan = ShmLoan::factory()->create();
        $this->scheduleOf($loan, [
            ['due_date' => today()->addDays(30)->toDateString()],
        ]);

        $this->actingAs($this->admin)
            ->get(route('shm.collection.index'))
            ->assertInertia(fn ($page) => $page->has('loans.data', 0));
    }

    public function test_active_loan_with_fully_paid_overdue_installment_does_not_appear(): void
    {
        $loan = $this->plainOverdueLoan(daysOverdue: 20);
        // Total angsuran 1.200.000 — pembayaran penuh melunasi tunggakan.
        $payment = $this->paymentFor($loan, 1_200_000, $this->admin);
        $this->allocate($payment);

        $this->actingAs($this->admin)
            ->get(route('shm.collection.index'))
            ->assertInertia(fn ($page) => $page->has('loans.data', 0));
    }

    public function test_closed_loan_does_not_appear(): void
    {
        $loan = ShmLoan::factory()->closed()->create();
        $this->scheduleOf($loan, [
            ['due_date' => today()->subDays(30)->toDateString()],
        ]);

        $this->actingAs($this->admin)
            ->get(route('shm.collection.index'))
            ->assertInertia(fn ($page) => $page->has('loans.data', 0));
    }

    public function test_undisbursed_loan_does_not_appear(): void
    {
        $loan = ShmLoan::factory()->create(['disbursed_at' => null]);
        $this->scheduleOf($loan, [
            ['due_date' => today()->subDays(30)->toDateString()],
        ]);

        $this->actingAs($this->admin)
            ->get(route('shm.collection.index'))
            ->assertInertia(fn ($page) => $page->has('loans.data', 0));
    }

    public function test_due_today_installment_is_not_yet_overdue(): void
    {
        $loan = ShmLoan::factory()->create();
        $this->scheduleOf($loan, [
            ['due_date' => today()->toDateString()],
        ]);

        $this->actingAs($this->admin)
            ->get(route('shm.collection.index'))
            ->assertInertia(fn ($page) => $page->has('loans.data', 0));
    }

    // ------------------------------------------------------------------
    // C. Calculation
    // ------------------------------------------------------------------

    public function test_overdue_summary_counts_only_overdue_unpaid_installments(): void
    {
        $loan = ShmLoan::factory()->create();
        $earliest = today()->subDays(60)->toDateString();
        $this->scheduleOf($loan, [
            ['due_date' => $earliest],
            ['due_date' => today()->subDays(30)->toDateString()],
            // Angsuran jatuh tempo masih akan datang — bukan tunggakan.
            ['due_date' => today()->addDays(30)->toDateString()],
        ]);

        $this->actingAs($this->admin)
            ->get(route('shm.collection.index'))
            ->assertInertia(fn ($page) => $page
                ->has('loans.data', 1)
                ->where('loans.data.0.earliest_overdue_due_date', $earliest)
                ->where('loans.data.0.days_overdue', 60)
                ->where('loans.data.0.overdue.installments_count', 2)
                ->where('loans.data.0.overdue.outstanding_principal', 2_000_000)
                ->where('loans.data.0.overdue.outstanding_interest', 400_000)
                ->where('loans.data.0.overdue.total_outstanding', 2_400_000));
    }

    public function test_default_sort_is_oldest_overdue_first(): void
    {
        $this->plainOverdueLoan(daysOverdue: 5, customerName: 'Nasabah T90 Baru');
        $this->plainOverdueLoan(daysOverdue: 60, customerName: 'Nasabah T90 Tua');

        $this->actingAs($this->admin)
            ->get(route('shm.collection.index'))
            ->assertInertia(fn ($page) => $page
                ->where('sort.key', null)
                ->where('loans.data.0.days_overdue', 60)
                ->where('loans.data.1.days_overdue', 5));
    }

    // ------------------------------------------------------------------
    // D. Partial payment
    // ------------------------------------------------------------------

    public function test_partial_principal_payment_is_reflected(): void
    {
        $loan = ShmLoan::factory()->create();
        $this->scheduleOf($loan, [
            ['due_date' => today()->subDays(30)->toDateString()],
        ]);

        // 500.000: bunga 200.000 lunas lebih dulu, sisanya 300.000 ke pokok
        // (urutan alokasi finansial asli bunga → pokok).
        $this->allocate($this->paymentFor($loan, 500_000, $this->admin));

        $this->actingAs($this->admin)
            ->get(route('shm.collection.index'))
            ->assertInertia(fn ($page) => $page
                ->has('loans.data', 1)
                ->where('loans.data.0.overdue.outstanding_principal', 700_000)
                ->where('loans.data.0.overdue.outstanding_interest', 0)
                ->where('loans.data.0.overdue.total_outstanding', 700_000)
                ->where('loans.data.0.overdue.installments_count', 1));
    }

    public function test_partial_interest_payment_is_reflected(): void
    {
        $loan = ShmLoan::factory()->create();
        $this->scheduleOf($loan, [
            ['due_date' => today()->subDays(30)->toDateString()],
        ]);

        // 100.000 semuanya jatuh ke bunga — pokok utuh, bunga tersisa 100.000.
        $this->allocate($this->paymentFor($loan, 100_000, $this->admin));

        $this->actingAs($this->admin)
            ->get(route('shm.collection.index'))
            ->assertInertia(fn ($page) => $page
                ->has('loans.data', 1)
                ->where('loans.data.0.overdue.outstanding_principal', 1_000_000)
                ->where('loans.data.0.overdue.outstanding_interest', 100_000)
                ->where('loans.data.0.overdue.total_outstanding', 1_100_000));
    }

    public function test_fully_paid_installment_leaves_only_remaining_overdue(): void
    {
        $loan = ShmLoan::factory()->create();
        $this->scheduleOf($loan, [
            ['due_date' => today()->subDays(60)->toDateString()],
            ['due_date' => today()->subDays(30)->toDateString()],
        ]);

        // Melunasi angsuran 1 tepat 1.200.000 — angsuran 2 belum tersentuh.
        $this->allocate($this->paymentFor($loan, 1_200_000, $this->admin));

        $this->actingAs($this->admin)
            ->get(route('shm.collection.index'))
            ->assertInertia(fn ($page) => $page
                ->has('loans.data', 1)
                ->where('loans.data.0.earliest_overdue_due_date', today()->subDays(30)->toDateString())
                ->where('loans.data.0.days_overdue', 30)
                ->where('loans.data.0.overdue.installments_count', 1)
                ->where('loans.data.0.overdue.total_outstanding', 1_200_000));
    }

    // ------------------------------------------------------------------
    // E. Ownership
    // ------------------------------------------------------------------

    public function test_marketing_without_view_all_only_sees_own_collection_loans(): void
    {
        $own = $this->overdueLoanOf($this->marketing, 'Nasabah Milik Marketing T90');
        $this->overdueLoanOf($this->marketing2, 'Nasabah Milik Marketing2 T90');

        $this->actingAs($this->marketing)
            ->get(route('shm.collection.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('loans.data', 1)
                ->where('loans.data.0.loan_number', $own->loan_number));
    }

    public function test_view_all_user_sees_all_collection_loans(): void
    {
        $this->overdueLoanOf($this->marketing, 'Nasabah Milik Marketing T90');
        $this->overdueLoanOf($this->marketing2, 'Nasabah Milik Marketing2 T90');

        $this->actingAs($this->admin)
            ->get(route('shm.collection.index'))
            ->assertInertia(fn ($page) => $page->has('loans.data', 2));
    }

    public function test_detail_ownership_is_enforced(): void
    {
        $loan = $this->overdueLoanOf($this->marketing2);

        $this->actingAs($this->marketing)
            ->get(route('shm.collection.show', $loan))
            ->assertForbidden();
    }

    // ------------------------------------------------------------------
    // F. Search / filter / sort / pagination (server-side)
    // ------------------------------------------------------------------

    public function test_search_by_loan_number(): void
    {
        $target = $this->plainOverdueLoan(customerName: 'Nasabah Pencarian T90');
        $this->plainOverdueLoan(customerName: 'Nasabah Lain T90');

        $this->actingAs($this->admin)
            ->get(route('shm.collection.index', ['search' => $target->loan_number]))
            ->assertInertia(fn ($page) => $page
                ->has('loans.data', 1)
                ->where('loans.data.0.loan_number', $target->loan_number));
    }

    public function test_search_by_customer_name(): void
    {
        $this->plainOverdueLoan(customerName: 'Nasabah Unik Sekali T90');
        $this->plainOverdueLoan(customerName: 'Nasabah Biasa T90');

        $this->actingAs($this->admin)
            ->get(route('shm.collection.index', ['search' => 'Unik Sekali T90']))
            ->assertInertia(fn ($page) => $page
                ->has('loans.data', 1)
                ->where('loans.data.0.customer.name', 'Nasabah Unik Sekali T90'));
    }

    public function test_product_filter_narrows_the_list(): void
    {
        $target = $this->plainOverdueLoan();
        $this->plainOverdueLoan();

        $this->actingAs($this->admin)
            ->get(route('shm.collection.index', [
                'product_id' => $target->shm_loan_product_id,
            ]))
            ->assertInertia(fn ($page) => $page
                ->has('loans.data', 1)
                ->where('loans.data.0.id', $target->id));
    }

    public function test_days_overdue_range_filter(): void
    {
        $old = $this->plainOverdueLoan(daysOverdue: 60, customerName: 'Tunggakan Tua T90');
        $fresh = $this->plainOverdueLoan(daysOverdue: 5, customerName: 'Tunggakan Baru T90');

        $this->actingAs($this->admin)
            ->get(route('shm.collection.index', ['min_days_overdue' => 30]))
            ->assertInertia(fn ($page) => $page
                ->has('loans.data', 1)
                ->where('loans.data.0.id', $old->id));

        $this->actingAs($this->admin)
            ->get(route('shm.collection.index', ['max_days_overdue' => 10]))
            ->assertInertia(fn ($page) => $page
                ->has('loans.data', 1)
                ->where('loans.data.0.id', $fresh->id));
    }

    public function test_pagination_is_server_side(): void
    {
        foreach (range(1, 26) as $index) {
            $this->plainOverdueLoan(customerName: 'Nasabah Halaman '.$index);
        }

        $this->actingAs($this->admin)
            ->get(route('shm.collection.index', ['per_page' => 10]))
            ->assertInertia(fn ($page) => $page
                ->has('loans.data', 10)
                ->where('loans.total', 26)
                ->where('loans.per_page', 10)
                ->where('loans.current_page', 1)
                ->where('loans.last_page', 3));
    }

    // ------------------------------------------------------------------
    // G. Detail
    // ------------------------------------------------------------------

    public function test_detail_shows_customer_loan_summary_schedule_and_payments(): void
    {
        $loan = $this->overdueLoanOf($this->marketing, 'Nasabah Detail T90');
        $member = Member::factory()->create();
        $loan->customer->update(['member_id' => $member->id]);

        // Pembayaran parsial 500.000 lewat service + alokasi asli.
        $this->allocate($this->paymentFor($loan, 500_000, $this->marketing));

        $this->actingAs($this->marketing)
            ->get(route('shm.collection.show', $loan))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Shm/Collection/Show')
                // A. Nasabah + kaitan anggota koperasi (relasi existing).
                // `name` = full_name anggota; guard terhadap pemilihan kolom
                // member yang salah (SQLite lens, PostgreSQL error).
                ->where('collection.loan.customer.name', 'Nasabah Detail T90')
                ->where('collection.loan.customer.member.member_number', $member->member_number)
                ->where('collection.loan.customer.member.name', $member->full_name)
                // B. Pinjaman.
                ->where('collection.loan.loan_number', $loan->loan_number)
                ->where('collection.loan.status', ShmLoan::STATUS_ACTIVE)
                ->where('collection.loan.disbursed_at', $loan->disbursed_at->toDateString())
                // C. Ringkasan tunggakan.
                ->where('collection.overdue.earliest_overdue_due_date', today()->subDays(30)->toDateString())
                ->where('collection.overdue.days_overdue', 30)
                ->where('collection.overdue.installments_count', 1)
                ->where('collection.overdue.outstanding_principal', 700_000)
                ->where('collection.overdue.outstanding_interest', 0)
                ->where('collection.overdue.total_outstanding', 700_000)
                // D. Jadwal + presentation state derived (bukan status DB).
                ->has('collection.schedule', 1)
                ->where('collection.schedule.0.state', 'overdue')
                ->where('collection.schedule.0.overdue_days', 30)
                ->where('collection.schedule.0.outstanding_principal', 700_000)
                ->where('collection.schedule.0.outstanding_interest', 0)
                // E. Riwayat pembayaran + alokasinya.
                ->has('collection.payments', 1)
                ->where('collection.payments.0.amount', 500_000)
                ->where('collection.payments.0.allocated_principal', 300_000)
                ->where('collection.payments.0.allocated_interest', 200_000)
                ->where('collection.payments.0.allocated_penalty', 0)
                ->where('collection.payments.0.unallocated', 0)
                // F. Timeline dari audit trail existing.
                ->whereType('timeline', 'array'));
    }

    public function test_detail_presentation_state_marks_paid_installment_as_lunas(): void
    {
        $loan = $this->overdueLoanOf($this->marketing, 'Nasabah Lunas T90');
        $this->allocate($this->paymentFor($loan, 1_200_000, $this->marketing));

        // Pinjaman tetap eligible (angsuran 2+) — buat angsuran kedua agar
        // pinjaman tetap muncul walau angsuran 1 sudah lunas.
        $loan->installments()->create([
            'installment_number' => 2,
            'due_date' => today()->subDays(5)->toDateString(),
            'principal_amount' => 1_000_000,
            'interest_amount' => 200_000,
            'total_amount' => 1_200_000,
            'paid_amount' => 0,
            'paid_interest_amount' => 0,
            'paid_principal_amount' => 0,
            'remaining_amount' => 1_200_000,
            'status' => ShmLoanInstallment::STATUS_SCHEDULED,
        ]);

        $this->actingAs($this->marketing)
            ->get(route('shm.collection.show', $loan))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('collection.schedule', 2)
                ->where('collection.schedule.0.state', 'paid')
                ->where('collection.schedule.0.outstanding_total', 0)
                ->where('collection.schedule.1.state', 'overdue')
                ->where('collection.overdue.installments_count', 1)
                ->where('collection.overdue.earliest_overdue_due_date', today()->subDays(5)->toDateString()));
    }

    public function test_detail_rejects_non_candidate_loans(): void
    {
        // Pinjaman dengan angsuran masa depan saja.
        $future = ShmLoan::factory()->create();
        $this->scheduleOf($future, [
            ['due_date' => today()->addDays(30)->toDateString()],
        ]);

        // Pinjaman closed dengan tunggakan yang belum dibayar.
        $closed = ShmLoan::factory()->closed()->create();
        $this->scheduleOf($closed, [
            ['due_date' => today()->subDays(30)->toDateString()],
        ]);

        $this->actingAs($this->admin)
            ->get(route('shm.collection.show', $future))
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->get(route('shm.collection.show', $closed))
            ->assertNotFound();
    }

    // ------------------------------------------------------------------
    // H. Read-only guarantee
    // ------------------------------------------------------------------

    public function test_collection_get_requests_do_not_mutate_database(): void
    {
        $loan = $this->overdueLoanOf($this->marketing, 'Nasabah Readonly T90');
        $this->allocate($this->paymentFor($loan, 300_000, $this->marketing));

        $countsBefore = $this->tableCounts();

        $this->actingAs($this->admin)->get(route('shm.collection.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('shm.collection.show', $loan))->assertOk();

        $this->assertSame(
            $countsBefore,
            $this->tableCounts(),
            'GET Collection tidak boleh menambah/mengubah baris apa pun.',
        );
    }

    /**
     * @return array<string, int>
     */
    private function tableCounts(): array
    {
        return [
            'loans' => ShmLoan::query()->count(),
            'installments' => ShmLoanInstallment::query()->count(),
            'payments' => ShmLoanPayment::query()->count(),
            'allocations' => ShmLoanPaymentAllocation::query()->count(),
            'customers' => ShmCustomer::query()->count(),
            'applications' => ShmLoanApplication::query()->count(),
            'audit_logs' => AuditLog::query()->count(),
        ];
    }
}
