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
use App\Models\ShmLoanSettlement;
use App\Models\ShmSettlementDiscountPolicy;
use App\Models\User;
use App\Services\Shm\ShmLoanPaymentAllocationService;
use App\Services\Shm\ShmLoanPaymentService;
use App\Services\Shm\ShmLoanSettlementService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * T004 — SHM Settlement: threshold discount berbasis remaining interest
 * (edge case CLOSED) + REGULAR PAYMENT LOCK server-side.
 *
 * Mengunci:
 * - Edge case threshold (bagian 17 T004): remaining interest tepat
 *   Rp50.000.000 TIDAK dapat discount; Rp50.000.001 DAPAT discount;
 *   Rp100.000.000 discount = 100jt × persentase policy.
 * - Threshold DILARANG berbasis plafon/principal (bagian 5 T004).
 * - Regular payment lock (bagian 4/10 T004): alokasi pembayaran reguler
 *   DITOLAK server-side pada status pending/approved/payment_recorded/
 *   payment_verified; pembuatan pembayaran tetap tercatat (intake uang
 *   pelunasan T86); REJECTED → unlocked.
 */
class ShmSettlementT004RuleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->user = $this->userWithPermissions([
            'shm.view',
            'shm.payment.create',
            'shm.settlement.create',
        ]);
    }

    private function userWithPermissions(array $permissionNames): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $role = Role::query()->create([
            'name' => 't004-'.Str::uuid(),
            'display_name' => 'T004 Tester',
            'is_system' => false,
        ]);

        $role->permissions()->attach(
            Permission::query()->whereIn('name', $permissionNames)->pluck('id')
        );

        $user->roles()->attach($role->id);

        return $user;
    }

    /**
     * Pinjaman aktif 100jt dengan dua angsuran: #1 jatuh tempo tempo lalu
     * (bunga 1jt — TIDAK didiskon), #2 masa depan dengan bunga $futureInterest
     * — remaining interest = $futureInterest (basis threshold T004).
     */
    private function activeLoanWithFutureInterest(float $futureInterest): ShmLoan
    {
        $application = ShmLoanApplication::query()->create([
            'application_number' => 'PENGAJ-T004-'.Str::upper(Str::random(10)),
            'shm_customer_id' => ShmCustomer::factory()->create()->id,
            'application_date' => now()->toDateString(),
            'principal' => '100000000',
            'tenor_months' => 2,
            'first_due_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'marketing_id' => $this->user->id,
            'status' => 'approved',
        ]);

        $loan = ShmLoan::factory()->create([
            'shm_customer_id' => $application->shm_customer_id,
            'shm_loan_application_id' => $application->id,
            'principal' => '100000000',
            'tenor_months' => 2,
        ]);

        foreach ([
            ['due' => now()->subMonth()->startOfMonth(), 'principal' => 5_000_000, 'interest' => 1_000_000],
            ['due' => now()->addMonth()->startOfMonth(), 'principal' => 5_000_000, 'interest' => $futureInterest],
        ] as $index => $spec) {
            $total = $spec['principal'] + $spec['interest'];

            $loan->installments()->create([
                'installment_number' => $index + 1,
                'due_date' => $spec['due']->toDateString(),
                'principal_amount' => $spec['principal'],
                'interest_amount' => $spec['interest'],
                'total_amount' => $total,
                'paid_amount' => 0,
                'paid_interest_amount' => 0,
                'paid_principal_amount' => 0,
                'remaining_amount' => $total,
                'status' => ShmLoanInstallment::STATUS_SCHEDULED,
            ]);
        }

        return $loan;
    }

    private function policy(string $percent): ShmSettlementDiscountPolicy
    {
        return ShmSettlementDiscountPolicy::query()->create([
            'min_remaining_months' => 1,
            'max_remaining_months' => 3,
            'discount_percent' => $percent,
            'is_active' => true,
        ]);
    }

    private function paymentFor(ShmLoan $loan, string $amount): ShmLoanPayment
    {
        return app(ShmLoanPaymentService::class)->create($loan, [
            'payment_date' => now()->toDateString(),
            'amount' => $amount,
        ], (int) $this->user->id);
    }

    private function requested(ShmLoan $loan): ShmLoanSettlement
    {
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.store', $loan), ['penalty' => 0])
            ->assertSessionHas('success');

        return ShmLoanSettlement::query()
            ->where('shm_loan_id', $loan->id)
            ->orderByDesc('id')
            ->firstOrFail();
    }

    // ------------------------------------------------------------------
    // Edge case threshold (bagian 17 T004) — basis remaining interest
    // ------------------------------------------------------------------

    public function test_remaining_interest_exactly_50m_gets_no_discount(): void
    {
        // Case A: remaining interest TEPAT Rp50.000.000 → TIDAK eligible,
        // meski policy tersedia (threshold = strictly greater than).
        $loan = $this->activeLoanWithFutureInterest(50_000_000);
        $this->policy('10.00');

        $settlement = $this->requested($loan);

        $this->assertFalse($settlement->discount_eligible);
        $this->assertFalse($settlement->discount_applied);
        $this->assertSame('0.00', (string) $settlement->discount_amount);
        // Bunga masa depan penuh (flat tanpa potongan).
        $this->assertSame('50000000.00', (string) $settlement->remaining_interest_amount);
        $this->assertSame('50000000.00', (string) $settlement->interest_after_discount_amount);
        // 10jt pokok + 1jt due + 50jt future penuh.
        $this->assertSame('61000000.00', (string) $settlement->settlement_amount);
    }

    public function test_remaining_interest_50m_plus_1_gets_policy_discount(): void
    {
        // Case B: remaining interest Rp50.000.001 → eligible; discount =
        // remaining interest × persentase policy (10% → 5.000.000,10
        // dibulatkan rupiah terdekat = 5.000.000).
        $loan = $this->activeLoanWithFutureInterest(50_000_001);
        $this->policy('10.00');

        $settlement = $this->requested($loan);

        $this->assertTrue($settlement->discount_eligible);
        $this->assertTrue($settlement->discount_applied);
        $this->assertSame('10.00', (string) $settlement->discount_percent);
        $this->assertSame('50000001.00', (string) $settlement->discount_base_amount);
        $this->assertSame('5000000.00', (string) $settlement->discount_amount);
        $this->assertSame('45000001.00', (string) $settlement->interest_after_discount_amount);
        // 10jt pokok + 1jt due + 45.000.001 future setelah diskon.
        $this->assertSame('56000001.00', (string) $settlement->settlement_amount);
    }

    public function test_remaining_interest_100m_discount_is_times_policy_percent(): void
    {
        // Case C: remaining interest Rp100.000.000 → discount = 100jt × 10%.
        $loan = $this->activeLoanWithFutureInterest(100_000_000);
        $this->policy('10.00');

        $settlement = $this->requested($loan);

        $this->assertTrue($settlement->discount_eligible);
        $this->assertTrue($settlement->discount_applied);
        $this->assertSame('100000000.00', (string) $settlement->discount_base_amount);
        $this->assertSame('10000000.00', (string) $settlement->discount_amount);
        $this->assertSame('90000000.00', (string) $settlement->interest_after_discount_amount);
        // 10jt pokok + 1jt due + 90jt future setelah diskon.
        $this->assertSame('101000000.00', (string) $settlement->settlement_amount);
    }

    // ------------------------------------------------------------------
    // Regular payment lock (bagian 4/10 T004) — server-side
    // ------------------------------------------------------------------

    public function test_regular_payment_allocation_is_locked_on_approved_settlement(): void
    {
        $loan = $this->activeLoanWithFutureInterest(1_500_000);
        $this->policy('10.00');
        $this->requested($loan);
        $this->actingAs($this->user)->post(route('shm.loans.pelunasan.approve', $loan));

        $payment = $this->paymentFor($loan, '2000000');

        try {
            app(ShmLoanPaymentAllocationService::class)->allocate($payment);
            $this->fail('Alokasi reguler harus ditolak saat settlement APPROVED (T004 lock).');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('payment', $exception->errors());
            $this->assertStringContainsString('dikunci', $exception->errors()['payment'][0]);
        }

        $this->assertSame(0, ShmLoanPaymentAllocation::query()->count());
        $this->assertSame(0, $payment->refresh()->allocations()->count());
    }

    public function test_regular_payment_allocation_is_locked_on_payment_recorded_settlement(): void
    {
        $loan = $this->activeLoanWithFutureInterest(1_500_000);
        $this->policy('10.00');
        $settlement = $this->requested($loan);
        $this->actingAs($this->user)->post(route('shm.loans.pelunasan.approve', $loan));

        $exact = $this->paymentFor($loan, (string) $settlement->settlement_amount);
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.payment', $loan), ['payment_id' => $exact->id])
            ->assertSessionHas('success');
        $this->assertSame(ShmLoanSettlement::STATUS_PAYMENT_RECORDED, $settlement->refresh()->status);

        $regular = $this->paymentFor($loan, '500000');

        try {
            app(ShmLoanPaymentAllocationService::class)->allocate($regular);
            $this->fail('Alokasi reguler harus ditolak saat settlement PAYMENT_RECORDED (T004 lock).');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('dikunci', $exception->errors()['payment'][0]);
        }

        $this->assertSame(0, ShmLoanPaymentAllocation::query()->count());
    }

    public function test_regular_payment_allocation_is_locked_on_payment_verified_settlement(): void
    {
        $loan = $this->activeLoanWithFutureInterest(1_500_000);
        $this->policy('10.00');
        $settlement = $this->requested($loan);
        $this->actingAs($this->user)->post(route('shm.loans.pelunasan.approve', $loan));

        $exact = $this->paymentFor($loan, (string) $settlement->settlement_amount);
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.payment', $loan), ['payment_id' => $exact->id]);
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.payment.verify', $loan))
            ->assertSessionHas('success');
        $this->assertSame(ShmLoanSettlement::STATUS_PAYMENT_VERIFIED, $settlement->refresh()->status);

        $regular = $this->paymentFor($loan, '500000');

        try {
            app(ShmLoanPaymentAllocationService::class)->allocate($regular);
            $this->fail('Alokasi reguler harus ditolak saat settlement PAYMENT_VERIFIED (T004 lock).');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('dikunci', $exception->errors()['payment'][0]);
        }

        $this->assertSame(0, ShmLoanPaymentAllocation::query()->count());
    }

    public function test_payment_creation_stays_available_during_lock_for_settlement_intake(): void
    {
        // Selama lock, pembuatan pembayaran TETAP tercatat (uang masuk T86 —
        // termasuk pembayaran pelunasan itu sendiri), tetapi alokasi ke
        // angsuran terkunci sehingga tidak pernah mengurangi kewajiban
        // angsuran dua jalur.
        $loan = $this->activeLoanWithFutureInterest(1_500_000);
        $this->policy('10.00');
        $this->requested($loan); // PENDING

        $payment = $this->paymentFor($loan, '750000');

        $this->assertSame(ShmLoanPayment::STATUS_POSTED, $payment->status);
        $this->assertSame(0, $payment->allocations()->count());

        try {
            app(ShmLoanPaymentAllocationService::class)->allocate($payment);
            $this->fail('Alokasi reguler harus ditolak selama settlement PENDING (T004 lock).');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('dikunci', $exception->errors()['payment'][0]);
        }

        $this->assertSame(0, ShmLoanPaymentAllocation::query()->count());
    }

    public function test_guard_2c_blocks_partially_allocated_payment_when_settlement_is_active(): void
    {
        // Section 10 Loophole Audit: payment yang sudah teralokasi SEBAGIAN
        // tetap harus DITOLAK oleh Guard 2c jika settlement aktif,
        // mencegah alokasi sisa dana mencuri angsuran di tengah settlement.
        $loan = $this->activeLoanWithFutureInterest(1_500_000);
        $this->policy('10.00');

        $payment = $this->paymentFor($loan, '5000000'); // 5jt
        $installment = $loan->installments()->first();

        // Alokasi parsial secara manual (2jt dari 5jt)
        $payment->allocations()->create([
            'shm_loan_installment_id' => $installment->id,
            'total_amount' => '2000000',
            'principal_amount' => '1500000',
            'interest_amount' => '500000',
            'penalty_amount' => '0',
            'allocated_at' => now(),
        ]);

        $this->assertFalse($payment->isFullyAllocated());
        $this->assertTrue($payment->allocations()->exists());

        // Settlement APPROVED
        $this->requested($loan);
        $this->actingAs($this->user)->post(route('shm.loans.pelunasan.approve', $loan));

        // Mencoba allocate sisa dana -> HARUS diblokir oleh Guard 2c!
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('dikunci');

        app(ShmLoanPaymentAllocationService::class)->allocate($payment);
    }

    public function test_guard_2c_allows_fully_allocated_payment_as_idempotent_noop(): void
    {
        $loan = $this->activeLoanWithFutureInterest(1_500_000);
        $this->policy('10.00');

        $payment = $this->paymentFor($loan, '2000000');
        $installment = $loan->installments()->first();

        // Alokasi penuh
        $payment->allocations()->create([
            'shm_loan_installment_id' => $installment->id,
            'total_amount' => '2000000',
            'principal_amount' => '1500000',
            'interest_amount' => '500000',
            'penalty_amount' => '0',
            'allocated_at' => now(),
        ]);

        $this->assertTrue($payment->isFullyAllocated());

        // Settlement APPROVED
        $this->requested($loan);
        $this->actingAs($this->user)->post(route('shm.loans.pelunasan.approve', $loan));

        // Replay allocation pada payment yang sudah fully allocated -> idempotent no-op (allocated: false)
        $result = app(ShmLoanPaymentAllocationService::class)->allocate($payment);

        $this->assertFalse($result['allocated']);
        $this->assertSame(0, $result['allocations']);
    }

    public function test_t89b_replacement_persists_settlement_released_at_and_audit(): void
    {
        $loan = $this->activeLoanWithFutureInterest(60_000_000);
        $this->policy('10.00');
        $settlement = $this->requested($loan);
        $this->actingAs($this->user)->post(route('shm.loans.pelunasan.approve', $loan));

        $payment1 = $this->paymentFor($loan, '50000000');
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.payment', $loan), ['payment_id' => $payment1->id])
            ->assertSessionHas('success');

        $this->assertNull($payment1->refresh()->settlement_released_at);

        // Penggantian payment dengan payment kedua
        $payment2 = $this->paymentFor($loan, (string) $settlement->settlement_amount);
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.payment', $loan), ['payment_id' => $payment2->id])
            ->assertSessionHas('success');

        $payment1->refresh();
        $this->assertNotNull($payment1->settlement_released_at);
        $this->assertTrue($payment1->isReleasedToRegularDomain());

        // Audit log terekam
        $this->assertSame(
            1,
            AuditLog::query()
                ->where('action', 'shm.loan.payment.settlement_released')
                ->where('object_id', $payment1->id)
                ->count()
        );
    }

    public function test_t89b_replacement_rollback_preserves_atomicity(): void
    {
        $loan = $this->activeLoanWithFutureInterest(60_000_000);
        $this->policy('10.00');
        $settlement = $this->requested($loan);
        $this->actingAs($this->user)->post(route('shm.loans.pelunasan.approve', $loan));

        $payment1 = $this->paymentFor($loan, '50000000');
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.payment', $loan), ['payment_id' => $payment1->id]);

        $this->assertNull($payment1->refresh()->settlement_released_at);

        // Simulasi exception di dalam transaksi recordPayment
        try {
            DB::transaction(function () use ($loan, $payment1, $settlement) {
                app(ShmLoanSettlementService::class)->recordPayment(
                    $loan,
                    $settlement,
                    $payment1,
                    (int) $this->user->id
                );
                throw new \RuntimeException('Simulated rollback');
            });
        } catch (\Throwable $e) {
            // expected
        }

        // settlement_released_at tidak bocor keluar transaksi
        $this->assertNull($payment1->refresh()->settlement_released_at);
    }

    public function test_t89b_released_payment_can_be_allocated_during_active_settlement(): void
    {
        $loan = $this->activeLoanWithFutureInterest(60_000_000);
        $this->policy('10.00');
        $settlement = $this->requested($loan);
        $this->actingAs($this->user)->post(route('shm.loans.pelunasan.approve', $loan));

        $payment1 = $this->paymentFor($loan, '2000000');
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.payment', $loan), ['payment_id' => $payment1->id]);

        // Ganti dengan payment2
        $payment2 = $this->paymentFor($loan, (string) $settlement->settlement_amount);
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.payment', $loan), ['payment_id' => $payment2->id]);

        // payment1 sekarang dirilis (settlement_released_at != null)
        $payment1->refresh();
        $this->assertTrue($payment1->isReleasedToRegularDomain());

        // Alokasi payment1 ke angsuran reguler sekarang DIIZINKAN meski settlement aktif
        $result = app(ShmLoanPaymentAllocationService::class)->allocate($payment1);
        $this->assertTrue($result['allocated']);
        $this->assertSame(1, $result['allocations']);
    }
}
