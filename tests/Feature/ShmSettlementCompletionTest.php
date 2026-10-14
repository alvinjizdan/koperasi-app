<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SavingsTransaction;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanInstallment;
use App\Models\ShmLoanPayment;
use App\Models\ShmLoanSettlement;
use App\Models\ShmSettlementDiscountPolicy;
use App\Models\User;
use App\Services\Shm\ShmLoanPaymentService;
use App\Services\Shm\ShmSettlementDiscountPolicyService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * T89B — Settlement Completion (Model B), Discount Policy & Overpayment.
 *
 * Mengunci workflow penuh: pending → approved → payment_recorded →
 * payment_verified → completed → loan closed; discount policy data-driven
 * (seleksi remaining months, effective dating, overlap, snapshot immutable);
 * penalty manual; verifikasi pembayaran EKSPLISIT (posted ≠ verified);
 * overpayment DITOLAK eksplisit sebagai BLOCKER BUSINESS DATA (hubungan
 * member tidak tersedia — tanpa membuat member, tanpa SHM Savings, tanpa
 * jurnal); idempotensi & constraint database untuk request paralel.
 */
class ShmSettlementCompletionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $marketing;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->admin = $this->userWithRole('admin');
        $this->marketing = $this->userWithRole('marketing');
        $this->user = $this->userWithPermissions([
            'shm.view',
            'shm.payment.create',
            'shm.settlement.create',
            'shm.settlement.policy.manage',
        ]);
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
            'name' => 't89b-'.Str::uuid(),
            'display_name' => 'T89B Tester',
            'is_system' => false,
        ]);

        $role->permissions()->attach(
            Permission::query()->whereIn('name', $permissionNames)->pluck('id')
        );

        $user->roles()->attach($role->id);

        return $user;
    }

    private function activeLoanOf(User $owner, string $principal = '100000000'): ShmLoan
    {
        $application = ShmLoanApplication::query()->create([
            'application_number' => 'PENGAJ-T89B-'.Str::upper(Str::random(10)),
            'shm_customer_id' => ShmCustomer::factory()->create()->id,
            'application_date' => now()->toDateString(),
            'principal' => $principal,
            'tenor_months' => 2,
            'first_due_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'marketing_id' => $owner->id,
            'status' => 'approved',
        ]);

        return ShmLoan::factory()->create([
            'shm_customer_id' => $application->shm_customer_id,
            'shm_loan_application_id' => $application->id,
            'principal' => $principal,
            'tenor_months' => 2,
        ]);
    }

    /**
     * Dua angsuran: #1 jatuh tempo tempo lalu, #2 masa depan.
     */
    private function scheduleWithDueAndFuture(ShmLoan $loan): void
    {
        foreach ([now()->subMonth()->startOfMonth(), now()->addMonth()->startOfMonth()] as $index => $due) {
            $loan->installments()->create([
                'installment_number' => $index + 1,
                'due_date' => $due->toDateString(),
                'principal_amount' => 5_000_000,
                'interest_amount' => 1_500_000,
                'total_amount' => 6_500_000,
                'paid_amount' => 0,
                'paid_interest_amount' => 0,
                'paid_principal_amount' => 0,
                'remaining_amount' => 6_500_000,
                'status' => ShmLoanInstallment::STATUS_SCHEDULED,
            ]);
        }
    }

    private function policy(int $min, int $max, string $percent, array $extra = []): ShmSettlementDiscountPolicy
    {
        return ShmSettlementDiscountPolicy::query()->create(array_merge([
            'min_remaining_months' => $min,
            'max_remaining_months' => $max,
            'discount_percent' => $percent,
            'is_active' => true,
        ], $extra));
    }

    private function paymentFor(ShmLoan $loan, string $amount): ShmLoanPayment
    {
        return app(ShmLoanPaymentService::class)->create($loan, [
            'payment_date' => now()->toDateString(),
            'amount' => $amount,
        ], (int) $this->user->id);
    }

    private function requested(ShmLoan $loan, array $extra = []): ShmLoanSettlement
    {
        $response = $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.store', $loan), array_merge(['penalty' => 0], $extra));

        $response->assertSessionHas('success');

        return ShmLoanSettlement::query()
            ->where('shm_loan_id', $loan->id)
            ->where('status', ShmLoanSettlement::STATUS_PENDING)
            ->orderByDesc('id')
            ->firstOrFail();
    }

    // ------------------------------------------------------------------
    // DISCOUNT POLICY (O.1–O.8)
    // ------------------------------------------------------------------

    public function test_policy_can_be_created_via_master_page(): void
    {
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.policies.store'), [
                'min_remaining_months' => 1,
                'max_remaining_months' => 3,
                'discount_percent' => 12.5,
                'is_active' => true,
            ])
            ->assertRedirect(route('shm.loans.pelunasan.policies.index'))
            ->assertSessionHas('success');

        $policy = ShmSettlementDiscountPolicy::query()->sole();
        $this->assertSame(1, $policy->min_remaining_months);
        $this->assertSame(3, $policy->max_remaining_months);
        $this->assertSame('12.50', (string) $policy->discount_percent);
        $this->assertTrue($policy->is_active);
    }

    public function test_policy_rejects_invalid_month_range(): void
    {
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.policies.store'), [
                'min_remaining_months' => 7,
                'max_remaining_months' => 3,
                'discount_percent' => 10,
            ])
            ->assertSessionHas('error', function (string $message): bool {
                $this->assertStringContainsString('bulan minimum tidak boleh melebihi', $message);

                return true;
            });

        $this->assertSame(0, ShmSettlementDiscountPolicy::query()->count());
    }

    public function test_policy_rejects_invalid_percentage(): void
    {
        foreach ([-5, 101] as $percent) {
            $this->actingAs($this->user)
                ->post(route('shm.loans.pelunasan.policies.store'), [
                    'min_remaining_months' => 1,
                    'max_remaining_months' => 3,
                    'discount_percent' => $percent,
                ])
                ->assertSessionHasErrors('discount_percent');
        }

        $this->assertSame(0, ShmSettlementDiscountPolicy::query()->count());
    }

    public function test_policy_rejects_overlapping_active_range_and_allows_inactive_overlap(): void
    {
        $this->policy(1, 6, '10.00');

        // Overlap AKTIF ditolak.
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.policies.store'), [
                'min_remaining_months' => 4,
                'max_remaining_months' => 9,
                'discount_percent' => 20,
            ])
            ->assertSessionHas('error', function (string $message): bool {
                $this->assertStringContainsString('beririsan', $message);

                return true;
            });

        $this->assertSame(1, ShmSettlementDiscountPolicy::query()->count());

        // Policy NON-AKTIF boleh beririsan (tidak ikut seleksi).
        $this->policy(4, 9, '20.00', ['is_active' => false]);
        $this->assertSame(2, ShmSettlementDiscountPolicy::query()->count());
    }

    public function test_policy_is_selected_by_remaining_months(): void
    {
        $this->policy(1, 3, '10.00');
        $second = $this->policy(4, 6, '20.00');

        $service = app(ShmSettlementDiscountPolicyService::class);

        $this->assertSame(1, $service->selectFor(2, now())->id);
        $this->assertSame($second->id, $service->selectFor(5, now())->id);
        $this->assertNull($service->selectFor(12, now())); // tanpa policy → null (dilarang 0% diam-diam)
    }

    public function test_policy_effective_dates_are_respected(): void
    {
        $this->policy(1, 3, '10.00', [
            'effective_from' => now()->addMonth()->toDateString(),
        ]);

        $service = app(ShmSettlementDiscountPolicyService::class);

        $this->assertNull($service->selectFor(2, now())); // belum berlaku hari ini
        $this->assertNotNull($service->selectFor(2, now()->addMonth()->addDays(1))); // sudah berlaku
    }

    public function test_used_policy_snapshot_is_immutable_after_policy_change(): void
    {
        $loan = $this->activeLoanOf($this->user);
        $this->scheduleWithDueAndFuture($loan);
        $policy = $this->policy(1, 3, '30.00');

        $settlement = $this->requested($loan);
        $this->assertSame('450000.00', (string) $settlement->discount_amount); // 30% × 1,5jt

        // Admin mengubah policy menjadi 40%.
        $this->actingAs($this->user)
            ->put(route('shm.loans.pelunasan.policies.update', $policy), [
                'min_remaining_months' => 1,
                'max_remaining_months' => 3,
                'discount_percent' => 40,
                'is_active' => true,
            ])
            ->assertSessionHas('success');

        // Settlement LAMA tidak dihitung ulang (snapshot per baris).
        $settlement->refresh();
        $this->assertSame('30.00', (string) $settlement->discount_percent);
        $this->assertSame('450000.00', (string) $settlement->discount_amount);
        $this->assertSame('1050000.00', (string) $settlement->interest_after_discount_amount);

        // Pengajuan BARU memakai policy terbaru.
        $loan2 = $this->activeLoanOf($this->user);
        $this->scheduleWithDueAndFuture($loan2);
        $settlement2 = $this->requested($loan2);
        $this->assertSame('40.00', (string) $settlement2->discount_percent);
        $this->assertSame('600000.00', (string) $settlement2->discount_amount);
    }

    public function test_discount_base_is_future_interest_only_due_interest_not_discounted(): void
    {
        $loan = $this->activeLoanOf($this->user);
        $this->scheduleWithDueAndFuture($loan);
        $this->policy(1, 3, '50.00');

        $settlement = $this->requested($loan);

        // Base = future interest 1,5jt (bukan due 1,5jt, bukan total 3jt).
        $this->assertSame('1500000.00', (string) $settlement->discount_base_amount);
        $this->assertSame('750000.00', (string) $settlement->discount_amount);
        $this->assertSame('750000.00', (string) $settlement->interest_after_discount_amount);
        // Due interest tetap penuh sebagai komponen terpisah.
        $this->assertSame('1500000.00', (string) $settlement->outstanding_interest);
        // 10jt pokok + 1,5jt due + 750rb future setelah diskon.
        $this->assertSame('12250000.00', (string) $settlement->settlement_amount);
    }

    // ------------------------------------------------------------------
    // WORKFLOW (O.17–O.24)
    // ------------------------------------------------------------------

    public function test_full_workflow_pending_to_completed_closes_loan_exactly_once(): void
    {
        $loan = $this->activeLoanOf($this->user);
        $this->scheduleWithDueAndFuture($loan);
        $this->policy(1, 3, '10.00');

        // 1. Pending.
        $settlement = $this->requested($loan, ['penalty' => 0]);
        $this->assertSame(ShmLoanSettlement::STATUS_PENDING, $settlement->status);
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->refresh()->status);

        // 2. Approved.
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.approve', $loan))
            ->assertSessionHas('success');
        $this->assertSame(ShmLoanSettlement::STATUS_APPROVED, $settlement->refresh()->status);
        // Approved TANPA pembayaran: pinjaman belum boleh ditutup.
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->refresh()->status);

        // 3. Payment recorded (Payment T86, nominal tepat kewajiban).
        $payment = $this->paymentFor($loan, '12850000'); // 10jt + 1,5jt + 1,35jt
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.payment', $loan), ['payment_id' => $payment->id])
            ->assertSessionHas('success');
        $this->assertSame(ShmLoanSettlement::STATUS_PAYMENT_RECORDED, $settlement->refresh()->status);
        // Recorded BELUM verified: pinjaman tetap aktif.
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->refresh()->status);

        // 4. Payment verified (EKSPLISIT).
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.payment.verify', $loan))
            ->assertSessionHas('success');
        $this->assertSame(ShmLoanSettlement::STATUS_PAYMENT_VERIFIED, $settlement->refresh()->status);
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->refresh()->status);

        // 5. Completed → loan closed, angsuran dis settlementkan.
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.complete', $loan))
            ->assertSessionHas('success');

        $settlement->refresh();
        $loan->refresh();
        $this->assertSame(ShmLoanSettlement::STATUS_COMPLETED, $settlement->status);
        $this->assertSame(ShmLoan::STATUS_CLOSED, $loan->status);
        // Principal TIDAK berubah.
        $this->assertSame('100000000.00', (string) $loan->principal);

        foreach ($loan->installments()->get() as $installment) {
            $this->assertSame(ShmLoanInstallment::STATUS_PAID, $installment->status);
            $this->assertSame('0.00', (string) $installment->remaining_amount);
        }

        // Tepat SATU audit completion & SATU audit penutupan.
        $this->assertSame(1, AuditLog::query()->where('action', 'shm.loan.settled')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'shm.loan.closed')->count());

        // 6. IDEMPOTEN: retry completion tidak menggandakan apa pun.
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.complete', $loan))
            ->assertSessionHas('success');

        $this->assertSame(1, ShmLoanSettlement::query()->where('status', ShmLoanSettlement::STATUS_COMPLETED)->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'shm.loan.settled')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'shm.loan.closed')->count());
    }

    public function test_payment_recorded_but_unverified_cannot_complete(): void
    {
        $loan = $this->activeLoanOf($this->user);
        $this->scheduleWithDueAndFuture($loan);
        $this->policy(1, 3, '10.00');

        $settlement = $this->requested($loan);
        $this->actingAs($this->user)->post(route('shm.loans.pelunasan.approve', $loan));

        $payment = $this->paymentFor($loan, '12850000');
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.payment', $loan), ['payment_id' => $payment->id]);

        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.complete', $loan))
            ->assertSessionHas('error', function (string $message): bool {
                $this->assertStringContainsString('payment_verified', $message);

                return true;
            });

        $this->assertSame(ShmLoanSettlement::STATUS_PAYMENT_RECORDED, $settlement->refresh()->status);
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->refresh()->status);
        $this->assertSame(0, AuditLog::query()->where('action', 'shm.loan.settled')->count());
    }

    public function test_insufficient_payment_fails_verification_and_can_be_replaced(): void
    {
        $loan = $this->activeLoanOf($this->user);
        $this->scheduleWithDueAndFuture($loan);
        $this->policy(1, 3, '10.00');

        $settlement = $this->requested($loan);
        $this->actingAs($this->user)->post(route('shm.loans.pelunasan.approve', $loan));

        $short = $this->paymentFor($loan, '10000000');
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.payment', $loan), ['payment_id' => $short->id]);

        // Verifikasi menolak nominal kurang.
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.payment.verify', $loan))
            ->assertSessionHas('error', function (string $message): bool {
                $this->assertStringContainsString('kurang dari kewajiban', $message);

                return true;
            });
        $this->assertSame(ShmLoanSettlement::STATUS_PAYMENT_RECORDED, $settlement->refresh()->status);

        // Pembayaran tepat menggantikan tautan → verifikasi lulus.
        $exact = $this->paymentFor($loan, '12850000');
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.payment', $loan), ['payment_id' => $exact->id])
            ->assertSessionHas('success');
        $this->assertSame($exact->id, $settlement->refresh()->shm_loan_payment_id);

        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.payment.verify', $loan))
            ->assertSessionHas('success');
        $this->assertSame(ShmLoanSettlement::STATUS_PAYMENT_VERIFIED, $settlement->refresh()->status);
    }

    public function test_rejected_settlement_cannot_close_loan_but_allows_resubmission(): void
    {
        $loan = $this->activeLoanOf($this->user);
        $this->scheduleWithDueAndFuture($loan);
        $this->policy(1, 3, '10.00');

        $first = $this->requested($loan);

        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.reject', $loan), ['reason' => 'Data belum lengkap.'])
            ->assertSessionHas('success');

        $this->assertSame(ShmLoanSettlement::STATUS_REJECTED, $first->refresh()->status);

        // Rejected tidak bisa di-approve/diselesaikan.
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.complete', $loan))
            ->assertSessionHas('error');

        // Pengajuan ulang = baris baru.
        $second = $this->requested($loan);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(ShmLoanSettlement::STATUS_PENDING, $second->status);
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->refresh()->status);
    }

    public function test_only_one_completed_settlement_per_loan_at_database_level(): void
    {
        $loan = $this->activeLoanOf($this->user);
        $this->scheduleWithDueAndFuture($loan);

        // Baris completed pertama (disisipkan langsung — level database).
        ShmLoanSettlement::query()->create([
            'shm_loan_id' => $loan->id,
            'settlement_reference' => 'SHM-SET-DUP-1',
            'settlement_date' => now()->toDateString(),
            'setstanding_placeholder' => null,
            'outstanding_principal' => 0,
            'outstanding_interest' => 0,
            'outstanding_penalty' => 0,
            'unallocated_payment_amount' => 0,
            'settlement_amount' => 100,
            'status' => ShmLoanSettlement::STATUS_COMPLETED,
            'created_by' => $this->user->id,
        ]);

        $this->expectException(QueryException::class);

        ShmLoanSettlement::query()->create([
            'shm_loan_id' => $loan->id,
            'settlement_reference' => 'SHM-SET-DUP-2',
            'settlement_date' => now()->toDateString(),
            'outstanding_principal' => 0,
            'outstanding_interest' => 0,
            'outstanding_penalty' => 0,
            'unallocated_payment_amount' => 0,
            'settlement_amount' => 100,
            'status' => ShmLoanSettlement::STATUS_COMPLETED,
            'created_by' => $this->user->id,
        ]);
    }

    // ------------------------------------------------------------------
    // OVERPAYMENT (O.25–O.30) — BLOCKER BUSINESS DATA yang deterministik
    // ------------------------------------------------------------------

    public function test_exact_payment_has_no_overpayment_and_completes(): void
    {
        $loan = $this->activeLoanOf($this->user);
        $this->scheduleWithDueAndFuture($loan);
        $this->policy(1, 3, '10.00');

        $settlement = $this->requested($loan);
        $this->actingAs($this->user)->post(route('shm.loans.pelunasan.approve', $loan));

        $payment = $this->paymentFor($loan, (string) $settlement->settlement_amount);
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.payment', $loan), ['payment_id' => $payment->id]);
        $this->actingAs($this->user)->post(route('shm.loans.pelunasan.payment.verify', $loan));
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.complete', $loan))
            ->assertSessionHas('success');

        $this->assertSame('0.00', (string) $settlement->refresh()->overpayment_amount);
        $this->assertSame(0, SavingsTransaction::query()->count());
    }

    public function test_overpayment_completion_is_blocked_explisit_without_any_savings_posting(): void
    {
        $loan = $this->activeLoanOf($this->user);
        $this->scheduleWithDueAndFuture($loan);
        $this->policy(1, 3, '10.00');

        $settlement = $this->requested($loan);
        $this->actingAs($this->user)->post(route('shm.loans.pelunasan.approve', $loan));

        // Bayar LEBIH dari kewajiban (13jt vs 12,85jt).
        $payment = $this->paymentFor($loan, '13000000');
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.payment', $loan), ['payment_id' => $payment->id]);
        $this->actingAs($this->user)->post(route('shm.loans.pelunasan.payment.verify', $loan));

        // Completion DITOLAK eksplisit: blocker hubungan member → Simpanan Pokok.
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.complete', $loan))
            ->assertSessionHas('error', function (string $message): bool {
                $this->assertStringContainsString('Simpanan Pokok', $message);
                $this->assertStringContainsString('BLOCKER BUSINESS DATA', $message);
                $this->assertStringContainsString('konversi/pembuatan member dilarang', $message);

                return true;
            });

        // Tidak ada tulisan finansial apa pun dari penolakan (retry aman).
        $this->assertSame(ShmLoanSettlement::STATUS_PAYMENT_VERIFIED, $settlement->refresh()->status);
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->refresh()->status);
        $this->assertSame('100000000.00', (string) $loan->refresh()->principal);
        $this->assertSame(0, SavingsTransaction::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'shm.loan.settled')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'shm.loan.closed')->count());

        // Retry dua kali lagi → deterministik, tetap nol posting/audit.
        $this->actingAs($this->user)->post(route('shm.loans.pelunasan.complete', $loan))->assertSessionHas('error');
        $this->actingAs($this->user)->post(route('shm.loans.pelunasan.complete', $loan))->assertSessionHas('error');
        $this->assertSame(0, SavingsTransaction::query()->count());
        $this->assertSame(1, ShmLoanSettlement::query()->where('shm_loan_id', $loan->id)->count());
    }

    public function test_settlement_payment_is_not_allocated_by_settlement_flow(): void
    {
        // Settlement payment TIDAK mengalokasikan ke angsuran (formula T87
        // tidak mengenal discount): alokasi tetap nol; pen settlementan
        // angsuran hanya terjadi saat completion.
        $loan = $this->activeLoanOf($this->user);
        $this->scheduleWithDueAndFuture($loan);
        $this->policy(1, 3, '10.00');

        $settlement = $this->requested($loan);
        $this->actingAs($this->user)->post(route('shm.loans.pelunasan.approve', $loan));

        $payment = $this->paymentFor($loan, (string) $settlement->settlement_amount);
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.payment', $loan), ['payment_id' => $payment->id]);

        $this->assertSame(
            0,
            DB::table('shm_loan_payment_allocations')
                ->where('shm_loan_payment_id', $payment->id)
                ->count(),
        );
    }

    // ------------------------------------------------------------------
    // AUTHORIZATION (O.31–O.33)
    // ------------------------------------------------------------------

    public function test_workflow_writes_require_settlement_permission_and_ownership(): void
    {
        $loan = $this->activeLoanOf($this->marketing);
        $this->scheduleWithDueAndFuture($loan);
        $this->policy(1, 3, '10.00');

        // Marketing pemilik tanpa shm.settlement.create → 403.
        $this->assertFalse($this->marketing->hasPermission('shm.settlement.create'));
        $this->actingAs($this->marketing)
            ->post(route('shm.loans.pelunasan.store', $loan), ['penalty' => 0])
            ->assertForbidden();
        $this->assertSame(0, ShmLoanSettlement::query()->count());

        // Pemegang settlement.create tapi bukan pemilik → 403.
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.store', $loan), ['penalty' => 0])
            ->assertForbidden();

        // view_all (admin) boleh lintas pemilik.
        $this->actingAs($this->admin)
            ->post(route('shm.loans.pelunasan.store', $loan), ['penalty' => 0])
            ->assertSessionHas('success');
        $this->assertSame(1, ShmLoanSettlement::query()->count());
    }

    public function test_policy_master_writes_require_dedicated_permission(): void
    {
        $viewer = $this->userWithPermissions(['shm.view']);

        $this->actingAs($viewer)
            ->get(route('shm.loans.pelunasan.policies.index'))
            ->assertOk();

        $this->actingAs($viewer)
            ->post(route('shm.loans.pelunasan.policies.store'), [
                'min_remaining_months' => 1,
                'max_remaining_months' => 3,
                'discount_percent' => 10,
            ])
            ->assertForbidden();

        // Marketing juga tidak memiliki policy.manage.
        $this->assertFalse($this->marketing->hasPermission('shm.settlement.policy.manage'));
    }
}
