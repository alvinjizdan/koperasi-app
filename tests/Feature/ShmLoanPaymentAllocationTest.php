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
use App\Services\Shm\ShmLoanPaymentService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Ticket 87 — Alokasi Pembayaran Pinjaman SHM ke Angsuran.
 *
 * Mengunci: urutan alokasi (penalty selalu 0 → bunga → pokok), alokasi
 * kronologis, partial & multi-angsuran, overpayment tersimpan sebagai
 * sisa belum teralokasi (tanpa penutupan pinjaman), idempotensi, proteksi
 * concurrent via unique constraint, audit tepat satu, otorisasi server-side,
 * serta BATAS keras: saldo pokok pinjaman tidak berubah dan T87 tidak
 * pernah menghitung penalty.
 */
class ShmLoanPaymentAllocationTest extends TestCase
{
    use RefreshDatabase;

    private const AUDIT_ACTION = 'shm.loan.payment.allocated';

    private User $admin;

    private User $marketing;

    private User $marketing2;

    /** Pemilik loan dengan shm.payment.create (role marketing default TIDAK memilikinya — pola T86). */
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
     * Pengguna dengan permission eksplisit tanpa mengubah role bawaan
     * (pola ShmLoanPaymentTest) — memiliki `shm.payment.create` tetapi
     * TANPA `shm.view_all`, sehingga scope kepemilikan tetap teruji.
     *
     * @param  array<int, string>  $permissionNames
     */
    private function userWithPermissions(array $permissionNames): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $role = Role::query()->create([
            'name' => 'alloc-tester-'.Str::uuid(),
            'display_name' => 'Allocation Tester',
            'is_system' => false,
        ]);

        $role->permissions()->attach(
            Permission::query()->whereIn('name', $permissionNames)->pluck('id')
        );

        $user->roles()->attach($role->id);

        return $user;
    }

    /** Pengajuan fixture milik $user (langsung ke model, tanpa alur form). */
    private function applicationOwnedBy(User $user): ShmLoanApplication
    {
        return ShmLoanApplication::query()->create([
            'application_number' => 'PENGAJ-PIN-'.Str::upper(Str::random(10)),
            'shm_customer_id' => ShmCustomer::factory()->create()->id,
            'application_date' => now()->toDateString(),
            'principal' => '100000000',
            'tenor_months' => 12,
            'first_due_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'marketing_id' => $user->id,
            'status' => 'approved',
        ]);
    }

    /** Pinjaman aktif & sudah dicairkan yang berasal dari pengajuan $user. */
    private function activeLoanOf(User $user): ShmLoan
    {
        $application = $this->applicationOwnedBy($user);

        return ShmLoan::factory()->create([
            'shm_customer_id' => $application->shm_customer_id,
            'shm_loan_application_id' => $application->id,
            'principal' => $application->principal,
            'tenor_months' => $application->tenor_months,
        ]);
    }

    /**
     * Buat $count angsuran jadwal sederhana untuk $loan.
     *
     * @param  array<int, array{principal?: float|int|string, interest?: float|int|string}>  $specs
     * @return Collection<int, ShmLoanInstallment>
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

    /** Catat pembayaran posted via service T86 (sumber transaksi existing). */
    private function paymentFor(ShmLoan $loan, float $amount, User $as): ShmLoanPayment
    {
        $service = app(ShmLoanPaymentService::class);

        return $service->create($loan, [
            'payment_date' => now()->toDateString(),
            'amount' => $amount,
        ], (int) $as->id);
    }

    private function allocate(ShmLoanPayment $payment, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->admin)->post(
            route('shm.loans.payments.allocate', $payment),
        );
    }

    // ------------------------------------------------------------------
    // A. Authorization
    // ------------------------------------------------------------------

    public function test_authorized_user_can_allocate_own_payment(): void
    {
        $owner = $this->userWithPermissions(['shm.view', 'shm.payment.create']);
        $loan = $this->activeLoanOf($owner);
        $this->scheduleOf($loan, [['principal' => 800000, 'interest' => 200000]]);
        $payment = $this->paymentFor($loan, 1_000_000, $owner);

        $this->allocate($payment, $owner)->assertRedirect(route('shm.loans.payments.show', $payment));

        $this->assertSame(1, ShmLoanPaymentAllocation::query()->count());
    }

    public function test_user_without_payment_permission_is_forbidden(): void
    {
        $owner = $this->userWithPermissions(['shm.view']);
        $loan = $this->activeLoanOf($owner);
        $this->scheduleOf($loan, [['principal' => 800000, 'interest' => 200000]]);
        $payment = $this->paymentFor($loan, 1_000_000, $this->admin);

        $this->actingAs($owner)
            ->post(route('shm.loans.payments.allocate', $payment))
            ->assertForbidden();

        $this->assertSame(0, ShmLoanPaymentAllocation::query()->count());
    }

    public function test_ownership_scope_is_enforced_for_allocation(): void
    {
        $loan = $this->activeLoanOf($this->marketing2);
        $this->scheduleOf($loan, [['principal' => 800000, 'interest' => 200000]]);
        $payment = $this->paymentFor($loan, 1_000_000, $this->marketing2);

        // Pemilik shm.payment.create tetapi BUKAN pemilik pinjaman
        // (pola ownerless T86) — scope kepemilikan menolak.
        $stranger = $this->userWithPermissions(['shm.view', 'shm.payment.create']);
        $this->allocate($payment, $stranger)->assertForbidden();

        $this->assertSame(0, ShmLoanPaymentAllocation::query()->count());
    }

    public function test_view_all_can_allocate_across_ownership(): void
    {
        $loan = $this->activeLoanOf($this->marketing2);
        $this->scheduleOf($loan, [['principal' => 800000, 'interest' => 200000]]);
        $payment = $this->paymentFor($loan, 1_000_000, $this->marketing2);

        // Admin memegang shm.view_all — boleh mengalokasikan lintas ownership.
        $this->allocate($payment, $this->admin)->assertRedirect();

        $this->assertSame(1, ShmLoanPaymentAllocation::query()->count());
    }

    // ------------------------------------------------------------------
    // B. Payment eligibility
    // ------------------------------------------------------------------

    public function test_payment_not_found_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('shm.loans.payments.allocate', 999999))
            ->assertNotFound();
    }

    public function test_non_posted_payment_cannot_be_allocated(): void
    {
        $loan = $this->activeLoanOf($this->payer);
        $this->scheduleOf($loan, [['principal' => 800000, 'interest' => 200000]]);
        $payment = $this->paymentFor($loan, 1_000_000, $this->payer);
        $payment->update(['status' => ShmLoanPayment::STATUS_REVERSED]);

        $this->allocate($payment)->assertSessionHasErrors('payment');

        $this->assertSame(0, ShmLoanPaymentAllocation::query()->count());
    }

    public function test_closed_loan_cannot_receive_allocation(): void
    {
        $loan = $this->activeLoanOf($this->payer);
        $this->scheduleOf($loan, [['principal' => 800000, 'interest' => 200000]]);
        $payment = $this->paymentFor($loan, 1_000_000, $this->payer);
        $loan->update(['status' => ShmLoan::STATUS_CLOSED]);

        $this->allocate($payment)->assertSessionHasErrors('payment');

        $this->assertSame(0, ShmLoanPaymentAllocation::query()->count());
    }

    public function test_undisbursed_loan_cannot_receive_allocation(): void
    {
        $loan = $this->activeLoanOf($this->payer);
        $this->scheduleOf($loan, [['principal' => 800000, 'interest' => 200000]]);
        $payment = $this->paymentFor($loan, 1_000_000, $this->payer);
        $loan->update(['disbursed_at' => null]);

        $this->allocate($payment)->assertSessionHasErrors('payment');

        $this->assertSame(0, ShmLoanPaymentAllocation::query()->count());
    }

    public function test_fully_allocated_payment_allocate_is_idempotent_noop(): void
    {
        $loan = $this->activeLoanOf($this->payer);
        $this->scheduleOf($loan, [['principal' => 800000, 'interest' => 200000]]);
        $payment = $this->paymentFor($loan, 1_000_000, $this->payer);

        $this->allocate($payment, $this->payer);
        $countAfterFirst = ShmLoanPaymentAllocation::query()->count();

        // Panggilan kedua: no-op, tidak membuat alokasi/audit baru.
        $this->allocate($payment, $this->payer)->assertSessionHas('success');

        $this->assertSame($countAfterFirst, ShmLoanPaymentAllocation::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', self::AUDIT_ACTION)->count());
    }

    // ------------------------------------------------------------------
    // C. Allocation behavior
    // ------------------------------------------------------------------

    public function test_full_installment_payment_marks_installment_paid(): void
    {
        $loan = $this->activeLoanOf($this->payer);
        $installments = $this->scheduleOf($loan, [['principal' => 800000, 'interest' => 200000]]);
        $payment = $this->paymentFor($loan, 1_000_000, $this->payer);

        $this->allocate($payment, $this->payer);

        $installment = $installments->first()->refresh();
        $this->assertSame('200000.00', (string) $installment->paid_interest_amount);
        $this->assertSame('800000.00', (string) $installment->paid_principal_amount);
        $this->assertSame('1000000.00', (string) $installment->paid_amount);
        $this->assertSame('0.00', (string) $installment->remaining_amount);
        $this->assertSame(ShmLoanInstallment::STATUS_PAID, $installment->status);
        $this->assertNotNull($installment->paid_at);
    }

    public function test_partial_payment_keeps_installment_scheduled(): void
    {
        $loan = $this->activeLoanOf($this->payer);
        $installments = $this->scheduleOf($loan, [['principal' => 800000, 'interest' => 200000]]);
        $payment = $this->paymentFor($loan, 500_000, $this->payer);

        $this->allocate($payment, $this->payer);

        $installment = $installments->first()->refresh();
        // Urutan: bunga 200rb lunas lebih dulu, lalu pokok 300rb.
        $this->assertSame('200000.00', (string) $installment->paid_interest_amount);
        $this->assertSame('300000.00', (string) $installment->paid_principal_amount);
        $this->assertSame('500000.00', (string) $installment->paid_amount);
        $this->assertSame('500000.00', (string) $installment->remaining_amount);
        // Status partially_paid masih PENDING — dipertahankan scheduled.
        $this->assertSame(ShmLoanInstallment::STATUS_SCHEDULED, $installment->status);
        $this->assertNull($installment->paid_at);
    }

    public function test_payment_spanning_multiple_installments_is_chronological(): void
    {
        $loan = $this->activeLoanOf($this->payer);
        $installments = $this->scheduleOf($loan, [
            ['principal' => 800000, 'interest' => 200000], // #1 total 1jt
            ['principal' => 800000, 'interest' => 200000], // #2 total 1jt
        ]);
        $payment = $this->paymentFor($loan, 1_500_000, $this->payer);

        $this->allocate($payment, $this->payer);

        $first = $installments->first()->refresh();
        $second = $installments->last()->refresh();

        // #1 lunas penuh.
        $this->assertSame(ShmLoanInstallment::STATUS_PAID, $first->status);
        $this->assertSame('1000000.00', (string) $first->paid_amount);
        // #2 kebagian sisa 500rb — bunga dulu lalu pokok 300rb.
        $this->assertSame('200000.00', (string) $second->paid_interest_amount);
        $this->assertSame('300000.00', (string) $second->paid_principal_amount);
        $this->assertSame(ShmLoanInstallment::STATUS_SCHEDULED, $second->status);

        // Urutan kronologis terjaga: alokasi ke #1 dibuat sebelum #2.
        $allocations = ShmLoanPaymentAllocation::query()->orderBy('id')->get();
        $this->assertSame(
            [$first->id, $second->id],
            $allocations->pluck('shm_loan_installment_id')->all(),
        );
    }

    public function test_exact_installment_amount_allocates_fully(): void
    {
        $loan = $this->activeLoanOf($this->payer);
        $installments = $this->scheduleOf($loan, [['principal' => 900000, 'interest' => 100000]]);
        $payment = $this->paymentFor($loan, 1_000_000, $this->payer);

        $this->allocate($payment, $this->payer);

        $installment = $installments->first()->refresh();
        $this->assertSame('1000000.00', (string) $installment->paid_amount);
        $this->assertSame(ShmLoanInstallment::STATUS_PAID, $installment->status);

        // Sisa tidak teralokasi = 0 (tepat pas).
        $this->assertSame('0.00', $payment->refresh()->unallocatedAmount());
    }

    public function test_overpayment_is_preserved_as_unallocated_without_loan_closure(): void
    {
        $loan = $this->activeLoanOf($this->payer);
        $this->scheduleOf($loan, [['principal' => 800000, 'interest' => 200000]]);
        // Bayar 2jt padahal total angsuran hanya 1jt.
        $payment = $this->paymentFor($loan, 2_000_000, $this->payer);

        $this->allocate($payment, $this->payer);

        $payment->refresh();
        // Overpayment TIDAK dipotong: amount tetap 2jt, 1jt sisa belum
        // teralokasi (perlakuan akhir PENDING T88).
        $this->assertSame('2000000.00', (string) $payment->amount);
        $this->assertSame('1000000.00', $payment->unallocatedAmount());
        $this->assertFalse($payment->isFullyAllocated());

        // Pinjaman TIDAK ditutup otomatis.
        $loan->refresh();
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->status);
        $this->assertSame('100000000.00', (string) $loan->principal);
    }

    public function test_penalty_is_never_calculated_and_stays_zero(): void
    {
        $loan = $this->activeLoanOf($this->payer);
        $this->scheduleOf($loan, [['principal' => 800000, 'interest' => 200000]]);
        $payment = $this->paymentFor($loan, 1_000_000, $this->payer);

        $this->allocate($payment, $this->payer);

        // Tidak ada formula penalty: kolom penalty_amount selalu 0.
        ShmLoanPaymentAllocation::query()->each(function (ShmLoanPaymentAllocation $allocation): void {
            $this->assertSame('0.00', (string) $allocation->penalty_amount);
        });
    }

    // ------------------------------------------------------------------
    // D. Financial invariants
    // ------------------------------------------------------------------

    public function test_payment_amount_is_unchanged_after_allocation(): void
    {
        $loan = $this->activeLoanOf($this->payer);
        $this->scheduleOf($loan, [['principal' => 800000, 'interest' => 200000]]);
        $payment = $this->paymentFor($loan, 750_000, $this->payer);

        $this->allocate($payment, $this->payer);

        $payment->refresh();
        $this->assertSame('750000.00', (string) $payment->amount);
    }

    public function test_allocation_never_exceeds_outstanding_and_never_negative(): void
    {
        $loan = $this->activeLoanOf($this->payer);
        $installments = $this->scheduleOf($loan, [['principal' => 800000, 'interest' => 200000]]);
        $payment = $this->paymentFor($loan, 1_500_000, $this->payer); // overpayment

        $this->allocate($payment, $this->payer);

        $installment = $installments->first()->refresh();

        // Alokasi per komponen tidak pernah melebihi original, outstanding
        // tidak pernah negatif.
        $this->assertLessThanOrEqual(
            (float) $installment->interest_amount,
            (float) $installment->paid_interest_amount,
        );
        $this->assertLessThanOrEqual(
            (float) $installment->principal_amount,
            (float) $installment->paid_principal_amount,
        );
        $this->assertGreaterThanOrEqual(0, (float) $installment->outstandingInterest());
        $this->assertGreaterThanOrEqual(0, (float) $installment->outstandingPrincipal());
        $this->assertSame('0.00', (string) $installment->remaining_amount);
    }

    public function test_loan_principal_is_unchanged_and_loan_not_closed(): void
    {
        $loan = $this->activeLoanOf($this->payer);
        $this->scheduleOf($loan, [['principal' => 800000, 'interest' => 200000]]);
        $payment = $this->paymentFor($loan, 1_000_000, $this->payer);

        $this->allocate($payment, $this->payer);

        $loan->refresh();
        $this->assertSame('100000000.00', (string) $loan->principal);
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->status);
    }

    public function test_allocation_totals_match_payment_within_outstanding(): void
    {
        $loan = $this->activeLoanOf($this->payer);
        $this->scheduleOf($loan, [['principal' => 800000, 'interest' => 200000]]);
        $payment = $this->paymentFor($loan, 600_000, $this->payer);

        $this->allocate($payment, $this->payer);

        $allocatedTotal = (float) ShmLoanPaymentAllocation::query()->sum('total_amount');
        $this->assertSame(600000.0, $allocatedTotal);

        // Invariant: amount = allocated + unallocated.
        $payment->refresh();
        $this->assertEqualsWithDelta(
            (float) $payment->amount,
            $allocatedTotal + (float) $payment->unallocatedAmount(),
            0.01,
        );
    }

    // ------------------------------------------------------------------
    // E. Idempotency / concurrency
    // ------------------------------------------------------------------

    public function test_allocating_same_payment_twice_does_not_double(): void
    {
        $loan = $this->activeLoanOf($this->payer);
        $installments = $this->scheduleOf($loan, [['principal' => 800000, 'interest' => 200000]]);
        $payment = $this->paymentFor($loan, 1_000_000, $this->payer);

        $this->allocate($payment, $this->payer);
        $this->allocate($payment, $this->payer);

        $this->assertSame(1, ShmLoanPaymentAllocation::query()->count());
        $installment = $installments->first()->refresh();
        // Tidak dobel: paid_amount tetap 1jt.
        $this->assertSame('1000000.00', (string) $installment->paid_amount);
    }

    public function test_database_unique_constraint_blocks_duplicate_allocation(): void
    {
        $loan = $this->activeLoanOf($this->payer);
        $installments = $this->scheduleOf($loan, [['principal' => 800000, 'interest' => 200000]]);
        $payment = $this->paymentFor($loan, 1_000_000, $this->payer);

        $this->allocate($payment, $this->payer);

        // Request paralel yang lolos cek aplikasi pun tetap ditolak UNIQUE
        // (payment, installment) di database.
        $this->expectException(QueryException::class);

        ShmLoanPaymentAllocation::query()->create([
            'shm_loan_payment_id' => $payment->id,
            'shm_loan_installment_id' => $installments->first()->id,
            'penalty_amount' => 0,
            'interest_amount' => 100,
            'principal_amount' => 0,
            'total_amount' => 100,
        ]);
    }

    // ------------------------------------------------------------------
    // F. Audit
    // ------------------------------------------------------------------

    public function test_successful_allocation_creates_exactly_one_audit(): void
    {
        $loan = $this->activeLoanOf($this->payer);
        $this->scheduleOf($loan, [
            ['principal' => 800000, 'interest' => 200000],
            ['principal' => 800000, 'interest' => 200000],
        ]);
        $payment = $this->paymentFor($loan, 1_500_000, $this->payer);

        $this->allocate($payment, $this->payer);

        $audit = AuditLog::query()->where('action', self::AUDIT_ACTION)->get();
        $this->assertCount(1, $audit);

        $changes = $audit->first()->changes;
        $this->assertSame($payment->payment_reference, $changes['payment_reference']);
        $this->assertSame('1500000.00', $changes['total_payment']);
        $this->assertSame('1500000.00', $changes['allocated_amount']);
        $this->assertSame('0.00', $changes['unallocated_amount']);
        $this->assertCount(2, $changes['affected_installments']);
    }

    public function test_failed_allocation_leaves_no_orphan_audit_or_rows(): void
    {
        $loan = $this->activeLoanOf($this->payer);
        $this->scheduleOf($loan, [['principal' => 800000, 'interest' => 200000]]);
        $payment = $this->paymentFor($loan, 1_000_000, $this->payer);

        // Pinjaman ditutup SEBELUM alokasi — guard menolak di dalam
        // transaksi; tidak boleh ada alokasi/audit yang tertulis.
        $loan->update(['status' => ShmLoan::STATUS_CLOSED]);

        $this->allocate($payment)->assertSessionHasErrors('payment');

        $this->assertSame(0, ShmLoanPaymentAllocation::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', self::AUDIT_ACTION)->count());
    }

    // ------------------------------------------------------------------
    // G. T86 boundary — pembuatan pembayaran tetap TIDAK mengalokasikan
    // ------------------------------------------------------------------

    public function test_payment_creation_still_does_not_allocate(): void
    {
        $loan = $this->activeLoanOf($this->payer);
        $installments = $this->scheduleOf($loan, [['principal' => 800000, 'interest' => 200000]]);
        $this->paymentFor($loan, 1_000_000, $this->payer);

        // Boundary T86 dipertahankan: pembuatan pembayaran tidak menyentuh
        // angsuran maupun membuat baris alokasi.
        $this->assertSame(0, ShmLoanPaymentAllocation::query()->count());
        $installment = $installments->first()->refresh();
        $this->assertSame('0.00', (string) $installment->paid_amount);
        $this->assertSame(ShmLoanInstallment::STATUS_SCHEDULED, $installment->status);
    }

    // ------------------------------------------------------------------
    // H. UI foundation — halaman detail menampilkan alokasi nyata
    // ------------------------------------------------------------------

    public function test_payment_detail_page_shows_allocation_breakdown(): void
    {
        $loan = $this->activeLoanOf($this->payer);
        $this->scheduleOf($loan, [['principal' => 800000, 'interest' => 200000]]);
        $payment = $this->paymentFor($loan, 500_000, $this->payer);
        $this->allocate($payment, $this->payer);

        $response = $this->actingAs($this->payer)
            ->get(route('shm.loans.payments.show', $payment));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Shm/Loans/PaymentShow')
            ->where('payment.allocation_summary.allocated_amount', '500000.00')
            ->where('payment.allocation_summary.unallocated_amount', '0.00')
            ->has('payment.allocations', 1)
            ->where('payment.allocations.0.interest_amount', '200000.00')
            ->where('payment.allocations.0.principal_amount', '300000.00')
            ->where('payment.allocations.0.penalty_amount', '0.00'),
        );
    }
}
