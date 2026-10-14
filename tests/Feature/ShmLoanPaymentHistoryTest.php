<?php

namespace Tests\Feature;

use App\Models\AuditLog;
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
 * Ticket 88 — Riwayat & Detail Pembayaran Pinjaman SHM (monitoring).
 *
 * Mengunci: otorisasi server-side (permission + ownership + view_all), query
 * history server-side (search / filter status / rentang tanggal / alokasi /
 * sorting whitelist / pagination), konten detail (pembayaran, pinjaman,
 * nasabah, breakdown alokasi kronologis, allocated/unallocated, timeline
 * audit), serta BATAS keras read-only: GET tidak pernah mengubah data
 * finansial maupun membuat audit baru.
 */
class ShmLoanPaymentHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $marketing;

    private User $marketing2;

    private User $payer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->admin = $this->userWithRole('admin');
        $this->marketing = $this->userWithRole('marketing');
        $this->marketing2 = $this->userWithRole('marketing');
        $this->payer = $this->userWithPermissions(['shm.view', 'shm.payment.create']);
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', $roleName)->value('id'));

        return $user;
    }

    /**
     * @param  array<int, string>  $permissionNames
     */
    private function userWithPermissions(array $permissionNames): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $role = Role::query()->create([
            'name' => 'history-tester-'.Str::uuid(),
            'display_name' => 'History Tester',
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

    private function activeLoanOf(User $user, ?string $customerName = null): ShmLoan
    {
        $application = $this->applicationOwnedBy($user, $customerName);

        return ShmLoan::factory()->create([
            'shm_customer_id' => $application->shm_customer_id,
            'shm_loan_application_id' => $application->id,
            'principal' => $application->principal,
            'tenor_months' => $application->tenor_months,
            // Dibawa dari pengajuan (pola T82) agar detail dapat menampilkan
            // jatuh tempo pertama yang persisted.
            'first_due_date' => $application->first_due_date,
        ]);
    }

    /**
     * @param  array<int, array{principal?: float|int|string, interest?: float|int|string}>  $specs
     */
    private function scheduleOf(ShmLoan $loan, array $specs): iterable
    {
        $installments = collect();

        foreach ($specs as $index => $spec) {
            $principal = (string) ($spec['principal'] ?? 1_000_000);
            $interest = (string) ($spec['interest'] ?? 200_000);
            $total = (string) ((float) $principal + (float) $interest);

            $installments->push($loan->installments()->create([
                'installment_number' => $index + 1,
                'due_date' => now()->addMonths($index + 1)->startOfMonth()->toDateString(),
                'principal_amount' => $principal,
                'interest_amount' => $interest,
                'total_amount' => $total,
                'paid_amount' => 0,
                'paid_interest_amount' => 0,
                'paid_principal_amount' => 0,
                'remaining_amount' => $total,
                'status' => ShmLoanInstallment::STATUS_SCHEDULED,
            ]));
        }

        return $installments;
    }

    private function paymentFor(ShmLoan $loan, float $amount, User $as, array $extra = []): ShmLoanPayment
    {
        return app(ShmLoanPaymentService::class)->create($loan, array_merge([
            'payment_date' => now()->toDateString(),
            'amount' => $amount,
        ], $extra), (int) $as->id);
    }

    // ------------------------------------------------------------------
    // A. History authorization
    // ------------------------------------------------------------------

    public function test_authorized_user_can_view_history(): void
    {
        $loan = $this->activeLoanOf($this->payer);
        $this->paymentFor($loan, 1_000_000, $this->payer);

        $this->actingAs($this->payer)
            ->get(route('shm.loans.payments.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Shm/Loans/Payments')
                ->has('payments.data', 1));
    }

    public function test_user_without_shm_view_is_rejected(): void
    {
        $user = $this->userWithPermissions(['dashboard.view']);

        $this->actingAs($user)
            ->get(route('shm.loans.payments.index'))
            ->assertForbidden();
    }

    public function test_ownership_scope_is_enforced_on_history(): void
    {
        $this->activeLoanOf($this->marketing2);
        $this->paymentFor(
            ShmLoan::query()->whereHas('application', fn ($q) => $q->where('marketing_id', $this->marketing2->id))->first(),
            500_000,
            $this->marketing2,
        );

        // Marketing tanpa view_all hanya melihat pembayaran milik sendiri.
        $response = $this->actingAs($this->marketing)
            ->get(route('shm.loans.payments.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('payments.data', 0));
    }

    public function test_view_all_can_see_cross_owner_payments(): void
    {
        $loan = $this->activeLoanOf($this->marketing2);
        $this->paymentFor($loan, 500_000, $this->marketing2);

        $this->actingAs($this->admin)
            ->get(route('shm.loans.payments.index'))
            ->assertInertia(fn ($page) => $page->has('payments.data', 1));
    }

    // ------------------------------------------------------------------
    // B. History query: search / filter / sort / pagination
    // ------------------------------------------------------------------

    public function test_search_by_payment_reference(): void
    {
        $loan = $this->activeLoanOf($this->payer);
        $payment = $this->paymentFor($loan, 1_000_000, $this->payer);
        $this->paymentFor($loan, 200_000, $this->payer);

        $this->actingAs($this->payer)
            ->get(route('shm.loans.payments.index', ['search' => $payment->payment_reference]))
            ->assertInertia(fn ($page) => $page
                ->has('payments.data', 1)
                ->where('payments.data.0.payment_reference', $payment->payment_reference));
    }

    public function test_search_by_customer_name(): void
    {
        $loan = $this->activeLoanOf($this->payer, 'Nasabah Unik T88');
        $this->paymentFor($loan, 1_000_000, $this->payer);

        $this->actingAs($this->payer)
            ->get(route('shm.loans.payments.index', ['search' => 'Unik T88']))
            ->assertInertia(fn ($page) => $page->has('payments.data', 1));
    }

    public function test_search_by_loan_number(): void
    {
        $loan = $this->activeLoanOf($this->payer);
        $this->paymentFor($loan, 1_000_000, $this->payer);

        $this->actingAs($this->payer)
            ->get(route('shm.loans.payments.index', ['search' => $loan->loan_number]))
            ->assertInertia(fn ($page) => $page->has('payments.data', 1));
    }

    public function test_filter_by_status(): void
    {
        $loan = $this->activeLoanOf($this->payer);
        $payment = $this->paymentFor($loan, 1_000_000, $this->payer);
        $payment->update(['status' => ShmLoanPayment::STATUS_REVERSED]);

        $this->actingAs($this->payer)
            ->get(route('shm.loans.payments.index', ['status' => 'posted']))
            ->assertInertia(fn ($page) => $page->has('payments.data', 0));

        $this->actingAs($this->payer)
            ->get(route('shm.loans.payments.index', ['status' => 'reversed']))
            ->assertInertia(fn ($page) => $page->has('payments.data', 1));
    }

    public function test_filter_by_payment_date_range(): void
    {
        $loan = $this->activeLoanOf($this->payer);
        $this->paymentFor($loan, 1_000_000, $this->payer, [
            'payment_date' => now()->subDays(10)->toDateString(),
        ]);
        $this->paymentFor($loan, 200_000, $this->payer);

        // Rentang yang hanya mencakup pembayaran lama.
        $this->actingAs($this->payer)
            ->get(route('shm.loans.payments.index', [
                'date_from' => now()->subDays(11)->toDateString(),
                'date_to' => now()->subDays(9)->toDateString(),
            ]))
            ->assertInertia(fn ($page) => $page->has('payments.data', 1));
    }

    public function test_filter_by_allocation_status(): void
    {
        $loan = $this->activeLoanOf($this->payer);
        $this->scheduleOf($loan, [['principal' => 800_000, 'interest' => 200_000]]);

        $allocated = $this->paymentFor($loan, 1_000_000, $this->payer);
        app(ShmLoanPaymentAllocationService::class)->allocate($allocated);

        $unallocated = $this->paymentFor($loan, 500_000, $this->payer);

        $this->actingAs($this->payer)
            ->get(route('shm.loans.payments.index', ['allocation' => 'full']))
            ->assertInertia(fn ($page) => $page
                ->has('payments.data', 1)
                ->where('payments.data.0.id', $allocated->id));

        $this->actingAs($this->payer)
            ->get(route('shm.loans.payments.index', ['allocation' => 'none']))
            ->assertInertia(fn ($page) => $page
                ->has('payments.data', 1)
                ->where('payments.data.0.id', $unallocated->id));
    }

    public function test_sorting_is_whitelisted_and_works(): void
    {
        $loan = $this->activeLoanOf($this->payer);
        $this->paymentFor($loan, 300_000, $this->payer);
        $this->paymentFor($loan, 900_000, $this->payer);

        $response = $this->actingAs($this->payer)
            ->get(route('shm.loans.payments.index', ['sort' => 'amount', 'direction' => 'asc']));

        $response->assertInertia(fn ($page) => $page
            ->where('payments.data.0.amount', '300000.00')
            ->where('payments.data.1.amount', '900000.00'));

        // Kolom di luar whitelist diabaikan tanpa error (urutan fallback).
        $this->actingAs($this->payer)
            ->get(route('shm.loans.payments.index', ['sort' => 'secret_column', 'direction' => 'asc']))
            ->assertOk();
    }

    public function test_pagination_preserves_search(): void
    {
        $loan = $this->activeLoanOf($this->payer, 'Nasabah Halaman');
        $this->paymentFor($loan, 100_000, $this->payer);

        $this->actingAs($this->payer)
            ->get(route('shm.loans.payments.index', ['search' => 'Halaman', 'per_page' => 10]))
            ->assertInertia(fn ($page) => $page
                ->where('payments.per_page', 10)
                ->has('payments.data', 1));
    }

    public function test_empty_result_is_graceful(): void
    {
        $this->activeLoanOf($this->payer);
        $this->paymentFor(ShmLoan::query()->first(), 100_000, $this->payer);

        $this->actingAs($this->payer)
            ->get(route('shm.loans.payments.index', ['search' => 'TIDAK-ADA-SAMA-SEKALI']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('payments.data', 0));
    }

    // ------------------------------------------------------------------
    // C. Detail authorization
    // ------------------------------------------------------------------

    public function test_authorized_user_can_view_detail(): void
    {
        $loan = $this->activeLoanOf($this->payer);
        $payment = $this->paymentFor($loan, 1_000_000, $this->payer);

        $this->actingAs($this->payer)
            ->get(route('shm.loans.payments.show', $payment))
            ->assertOk();
    }

    public function test_detail_of_foreign_loan_is_forbidden(): void
    {
        $loan = $this->activeLoanOf($this->marketing2);
        $payment = $this->paymentFor($loan, 1_000_000, $this->marketing2);

        $this->actingAs($this->marketing)
            ->get(route('shm.loans.payments.show', $payment))
            ->assertForbidden();
    }

    public function test_view_all_can_view_foreign_detail(): void
    {
        $loan = $this->activeLoanOf($this->marketing2);
        $payment = $this->paymentFor($loan, 1_000_000, $this->marketing2);

        $this->actingAs($this->admin)
            ->get(route('shm.loans.payments.show', $payment))
            ->assertOk();
    }

    // ------------------------------------------------------------------
    // D. Detail content
    // ------------------------------------------------------------------

    public function test_detail_shows_payment_loan_customer_and_allocation_breakdown(): void
    {
        $loan = $this->activeLoanOf($this->payer, 'Nasabah Detail T88');
        $this->scheduleOf($loan, [
            ['principal' => 800_000, 'interest' => 200_000],
            ['principal' => 800_000, 'interest' => 200_000],
        ]);
        $payment = $this->paymentFor($loan, 1_500_000, $this->payer, [
            'payment_channel' => 'tunai kasir',
            'notes' => 'catatan uji',
        ]);
        app(ShmLoanPaymentAllocationService::class)->allocate($payment);

        $response = $this->actingAs($this->payer)
            ->get(route('shm.loans.payments.show', $payment));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Shm/Loans/PaymentShow')
            ->where('payment.payment_reference', $payment->payment_reference)
            ->where('payment.amount', '1500000.00')
            ->where('payment.payment_channel', 'tunai kasir')
            ->where('payment.notes', 'catatan uji')
            ->where('payment.customer.name', 'Nasabah Detail T88')
            ->where('payment.loan.loan_number', $loan->loan_number)
            // Rate persisted (bukan dihitung ulang).
            ->where('payment.loan.rate_percent', (string) $loan->rate_percent)
            ->where('payment.loan.first_due_date', $loan->first_due_date->toDateString())
            // Breakdown alokasi kronologis: #1 lunas (1jt), #2 kebagian 500rb.
            ->has('payment.allocations', 2)
            ->where('payment.allocations.0.installment_number', 1)
            ->where('payment.allocations.0.interest_amount', '200000.00')
            ->where('payment.allocations.0.principal_amount', '800000.00')
            ->where('payment.allocations.0.due_date', $loan->installments()->orderBy('installment_number')->first()->due_date->toDateString())
            ->where('payment.allocations.1.installment_number', 2)
            ->where('payment.allocations.1.interest_amount', '200000.00')
            ->where('payment.allocations.1.principal_amount', '300000.00')
            ->where('payment.allocation_summary.allocated_amount', '1500000.00')
            ->where('payment.allocation_summary.unallocated_amount', '0.00')
            ->has('timeline', 2),
        );
    }

    public function test_detail_shows_unallocated_overpayment_explicitly(): void
    {
        $loan = $this->activeLoanOf($this->payer);
        $this->scheduleOf($loan, [['principal' => 800_000, 'interest' => 200_000]]);
        $payment = $this->paymentFor($loan, 2_000_000, $this->payer);
        app(ShmLoanPaymentAllocationService::class)->allocate($payment);

        $this->actingAs($this->payer)
            ->get(route('shm.loans.payments.show', $payment))
            ->assertInertia(fn ($page) => $page
                ->where('payment.allocation_summary.allocated_amount', '1000000.00')
                ->where('payment.allocation_summary.unallocated_amount', '1000000.00')
                ->where('payment.allocation_summary.is_fully_allocated', false));
    }

    public function test_timeline_lists_created_and_allocated_events(): void
    {
        $loan = $this->activeLoanOf($this->payer);
        $this->scheduleOf($loan, [['principal' => 800_000, 'interest' => 200_000]]);
        $payment = $this->paymentFor($loan, 1_000_000, $this->payer);
        app(ShmLoanPaymentAllocationService::class)->allocate($payment);

        $this->actingAs($this->payer)
            ->get(route('shm.loans.payments.show', $payment))
            ->assertInertia(fn ($page) => $page
                ->where('timeline.0.action', 'shm.loan.payment.created')
                ->where('timeline.1.action', 'shm.loan.payment.allocated'));
    }

    // ------------------------------------------------------------------
    // E. Financial read-only boundary
    // ------------------------------------------------------------------

    public function test_get_detail_does_not_change_any_financial_state(): void
    {
        $loan = $this->activeLoanOf($this->payer);
        $installments = $this->scheduleOf($loan, [['principal' => 800_000, 'interest' => 200_000]]);
        $payment = $this->paymentFor($loan, 1_000_000, $this->payer);
        app(ShmLoanPaymentAllocationService::class)->allocate($payment);

        // Snapshots memakai string agar perbandingan Carbon stabil
        // (assertSame pada objek Carbon selalu gagal antar-instance).
        $snapshot = fn ($model, array $keys): array => array_merge(
            $model->only($keys),
            ['updated_at' => $model->updated_at?->toDateTimeString()],
        );

        $before = [
            'payment' => $snapshot($payment->refresh(), [
                'amount', 'status', 'payment_reference',
            ]),
            'installment' => $snapshot($installments->first()->refresh(), [
                'paid_amount', 'paid_interest_amount', 'paid_principal_amount',
                'remaining_amount', 'status',
            ]),
            'loan' => $snapshot($loan->refresh(), ['principal', 'status']),
            'allocations' => ShmLoanPaymentAllocation::query()->count(),
            'payment_audit' => AuditLog::query()
                ->where('action', 'shm.loan.payment.created')->count(),
            'allocation_audit' => AuditLog::query()
                ->where('action', 'shm.loan.payment.allocated')->count(),
        ];

        // Buka history + detail (GET saja) — berulang untuk keyakinan.
        $this->actingAs($this->payer)->get(route('shm.loans.payments.index'))->assertOk();
        $this->actingAs($this->payer)->get(route('shm.loans.payments.show', $payment))->assertOk();
        $this->actingAs($this->payer)->get(route('shm.loans.payments.show', $payment))->assertOk();

        $this->assertSame($before['payment'], $snapshot($payment->refresh(), ['amount', 'status', 'payment_reference']));
        $this->assertSame($before['installment'], $snapshot($installments->first()->refresh(), [
            'paid_amount', 'paid_interest_amount', 'paid_principal_amount',
            'remaining_amount', 'status',
        ]));
        $this->assertSame($before['loan'], $snapshot($loan->refresh(), ['principal', 'status']));
        $this->assertSame($before['allocations'], ShmLoanPaymentAllocation::query()->count());
        $this->assertSame($before['payment_audit'], AuditLog::query()->where('action', 'shm.loan.payment.created')->count());
        $this->assertSame($before['allocation_audit'], AuditLog::query()->where('action', 'shm.loan.payment.allocated')->count());
    }

    public function test_viewing_detail_does_not_create_audit(): void
    {
        $loan = $this->activeLoanOf($this->payer);
        $payment = $this->paymentFor($loan, 100_000, $this->payer);

        $auditBefore = AuditLog::query()->count();

        $this->actingAs($this->payer)->get(route('shm.loans.payments.show', $payment))->assertOk();

        $this->assertSame($auditBefore, AuditLog::query()->count());
    }

    public function test_history_allocation_status_is_derived_accurately(): void
    {
        $loan = $this->activeLoanOf($this->payer);
        $this->scheduleOf($loan, [
            ['principal' => 800_000, 'interest' => 200_000],
            ['principal' => 800_000, 'interest' => 200_000],
        ]);

        // None — pembayaran belum pernah dialokasikan.
        $none = $this->paymentFor($loan, 100_000, $this->payer);
        // Full — nominal ≤ outstanding angsuran pertama (1jt), dialokasi penuh.
        $full = $this->paymentFor($loan, 1_000_000, $this->payer);
        // Partial — overpayment: outstanding total hanya 2jt sehingga
        // 2,5jt hanya teralokasi 2jt (sisa 500rb belum teralokasi).
        // Dengan T87, allocated < amount HANYA terjadi saat outstanding
        // habis di tengah alokasi.
        $partial = $this->paymentFor($loan, 2_500_000, $this->payer);

        $allocation = app(ShmLoanPaymentAllocationService::class);
        $allocation->allocate($full);
        $allocation->allocate($partial);

        $this->actingAs($this->payer)
            ->get(route('shm.loans.payments.index'))
            ->assertInertia(function ($page) use ($none, $partial, $full): void {
                $byId = collect($page->toArray()['props']['payments']['data'])
                    ->keyBy('id');

                $this->assertSame('none', $byId[$none->id]['allocation_status']);
                $this->assertSame('partial', $byId[$partial->id]['allocation_status']);
                $this->assertSame('full', $byId[$full->id]['allocation_status']);
                // $full (1jt) menghabiskan angsuran #1 lebih dulu, sehingga
                // $partial (2,5jt) hanya menemukan sisa outstanding 1jt
                // (angsuran #2) → teralokasi 1jt, sisa 1,5jt belum teralokasi.
                $this->assertSame('1000000.00', $byId[$partial->id]['allocated_amount']);
                $this->assertSame('1500000.00', $byId[$partial->id]['unallocated_amount']);
                $this->assertSame('0.00', $byId[$full->id]['unallocated_amount']);
            });
    }
}
