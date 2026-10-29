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
use App\Models\ShmLoanSettlement;
use App\Models\ShmSettlementDiscountPolicy;
use App\Models\User;
use App\Services\Shm\ShmLoanPaymentService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * T89D — Settlement contract enforcement + regression protection.
 *
 * Fokus: penegakan aturan yang sudah final (bukan ekspansi fitur):
 * - discount hanya eligible jika principal > 50jt;
 * - eligible tapi tidak ada policy → request ditolak eksplisit (bukan silent 0%);
 * - snapshot discount pada settlement YANG SUDAH DIBUAT tetap immutable meski
 *   master policy kemudian diubah/dinonaktifkan;
 * - hanya pembayaran TERRVERIFIKASI yang boleh jadi gate completion
 *   (posted/recorded saja TIDAK cukup);
 * - penutupan pinjaman HANYA terjadi melalui settlement completion yang sah;
 * - rejected settlement tidak menutup pinjaman dan tidak memposting apa pun.
 *
 * Overpayment: TIDAK diubah di ticket ini. Jika saat ini masih diblokir
 * eksplisit oleh service, test mendokumentasikan perilaku aktual tersebut
 * dan melaporkannya di final report sebagai item PENDING/BLOCKER sesuai
 * keadaannya.
 */
class ShmSettlementContractEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $admin;

    private User $marketing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->user = $this->userWithPermissions([
            'shm.view',
            'shm.payment.create',
            'shm.settlement.create',
            'shm.settlement.policy.manage',
        ]);
        $this->admin = $this->userWithRole('admin');
        $this->marketing = $this->userWithRole('marketing');
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', $roleName)->value('id'));

        return $user;
    }

    /** @param  array<int, string>  $permissionNames */
    private function userWithPermissions(array $permissionNames): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $role = Role::query()->create([
            'name' => 't89d-'.Str::uuid(),
            'display_name' => 'T89D Tester',
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
            'application_number' => 'PENGAJ-T89D-'.Str::upper(Str::random(10)),
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

    /** Dua angsuran: #1 tempo lalu, #2 masa depan. */
    private function scheduleWithDueAndFuture(ShmLoan $loan, float $futureInterest = 60_000_000): void
    {
        $due = now()->subMonth()->startOfMonth();
        $future = now()->addMonth()->startOfMonth();

        $loan->installments()->create([
            'installment_number' => 1,
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

        $loan->installments()->create([
            'installment_number' => 2,
            'due_date' => $future->toDateString(),
            'principal_amount' => 5_000_000,
            'interest_amount' => $futureInterest,
            'total_amount' => 5_000_000 + $futureInterest,
            'paid_amount' => 0,
            'paid_interest_amount' => 0,
            'paid_principal_amount' => 0,
            'remaining_amount' => 5_000_000 + $futureInterest,
            'status' => ShmLoanInstallment::STATUS_SCHEDULED,
        ]);
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

    // --------------------------------------------------------------------
    // 1. Discount eligibility: <= 50jt tidak dapat discount
    // --------------------------------------------------------------------

    public function test_loan_at_or_below_threshold_has_no_discount_even_if_policy_exists(): void
    {
        $loan = $this->activeLoanOf($this->user, '50000000');
        $this->scheduleWithDueAndFuture($loan, 1_500_000);
        $this->policy(1, 3, '20.00'); // policy memang ada, tapi tidak berlaku

        $settlement = $this->requested($loan);

        $this->assertFalse($settlement->discount_eligible);
        $this->assertFalse($settlement->discount_applied);
        $this->assertSame('0.00', (string) $settlement->discount_amount);
        // Bunga masa depan tetap penuh (flat tanpa potongan).
        $this->assertSame('1500000.00', (string) $settlement->remaining_interest_amount);
        $this->assertSame('1500000.00', (string) $settlement->interest_after_discount_amount);
        // 10jt pokok + 1,5jt due + 1,5jt future.
        $this->assertSame('13000000.00', (string) $settlement->settlement_amount);
    }

    public function test_loan_below_threshold_has_no_discount_even_with_future_interest(): void
    {
        $loan = $this->activeLoanOf($this->user, '10000000');
        $this->scheduleWithDueAndFuture($loan, 10_000_000);
        $this->policy(1, 3, '50.00');

        $settlement = $this->requested($loan);

        $this->assertFalse($settlement->discount_eligible);
        $this->assertFalse($settlement->discount_applied);
        $this->assertSame('0.00', (string) $settlement->discount_amount);
    }

    // --------------------------------------------------------------------
    // 2. Eligible + no policy → ditolak eksplisit, bukan silent 0%
    // --------------------------------------------------------------------

    public function test_eligible_loan_without_policy_is_rejected_not_implicit_zero_percent(): void
    {
        $loan = $this->activeLoanOf($this->user);
        $this->scheduleWithDueAndFuture($loan);
        // Tidak ada policy sama sekali.

        $response = $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.store', $loan), ['penalty' => 0]);

        $response->assertSessionHas('error', function (string $message): bool {
            return str_contains($message, 'policy diskon')
                && str_contains($message, 'belum dikonfigurkan')
                && str_contains($message, '50.000.000');
        });

        $this->assertSame(0, ShmLoanSettlement::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'shm.loan.settlement.requested')->count());
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->refresh()->status);
    }

    public function test_eligible_loan_with_policy_but_no_remaining_future_interest_can_still_be_requested(): void
    {
        // Jika sisa bunga masa depan = 0, sistem tidak billed discount,
        // tetapi ini bukan kasus "policy hilang" — trivial tidak ada base.
        $loan = $this->activeLoanOf($this->user);
        // Kedua angsuran sudah jatuh tempo (due tempo lalu) → future interest 0.
        foreach ([now()->subMonth()->startOfMonth(), now()->subMonth()->startOfMonth()] as $index => $due) {
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
        $this->policy(1, 3, '10.00');

        $settlement = $this->requested($loan);

        $this->assertFalse($settlement->discount_eligible);
        $this->assertFalse($settlement->discount_applied);
        // Tidak ada base → tidak ada yang didiskon (bukan kasus policy missing).
        $this->assertSame('0.00', (string) $settlement->discount_amount);
        $this->assertSame('0.00', (string) $settlement->interest_after_discount_amount);
    }

    // --------------------------------------------------------------------
    // 3. Snapshot discount immutable setelah settlement dibuat
    // --------------------------------------------------------------------

    public function test_snapshot_discount_on_existing_settlement_does_not_change_after_policy_edit(): void
    {
        $loan = $this->activeLoanOf($this->user);
        $this->scheduleWithDueAndFuture($loan);
        $policy = $this->policy(1, 3, '25.00');

        $settlement = $this->requested($loan);
        $this->assertSame('15000000.00', (string) $settlement->discount_amount); // 25% × 60jt
        $this->assertSame('25.00', (string) $settlement->discount_percent);

        // Ubah policy jadi 35% lewat master.
        $this->actingAs($this->user)
            ->put(route('shm.loans.pelunasan.policies.update', $policy), [
                'min_remaining_months' => 1,
                'max_remaining_months' => 3,
                'discount_percent' => 35,
                'is_active' => true,
            ])
            ->assertSessionHas('success');

        $settlement->refresh();
        // Settlement LAMA tetap snapshot lama.
        $this->assertSame('25.00', (string) $settlement->discount_percent);
        $this->assertSame('15000000.00', (string) $settlement->discount_amount);
        $this->assertSame('45000000.00', (string) $settlement->interest_after_discount_amount);

        // Pengajuan BARU memakai policy terbaru.
        $loan2 = $this->activeLoanOf($this->user);
        $this->scheduleWithDueAndFuture($loan2);
        $settlement2 = $this->requested($loan2);

        $this->assertSame('35.00', (string) $settlement2->discount_percent);
        $this->assertSame('21000000.00', (string) $settlement2->discount_amount);
    }

    public function test_snapshot_discount_on_existing_settlement_does_not_change_after_policy_deactivated(): void
    {
        $loan = $this->activeLoanOf($this->user);
        $this->scheduleWithDueAndFuture($loan);
        $policy = $this->policy(1, 3, '20.00');

        $settlement = $this->requested($loan);
        $this->assertSame('12000000.00', (string) $settlement->discount_amount);

        // Nonaktifkan policy.
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.policies.toggle', $policy))
            ->assertSessionHas('success');

        $settlement->refresh();
        // Settlement LAMA tetap menggunakan snapshot.
        $this->assertTrue($settlement->discount_applied);
        $this->assertSame('20.00', (string) $settlement->discount_percent);
        $this->assertSame('12000000.00', (string) $settlement->discount_amount);

        // Pengajuan BARU sekarang tidak dapat policy → ditolak.
        $loan2 = $this->activeLoanOf($this->user);
        $this->scheduleWithDueAndFuture($loan2);

        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.store', $loan2), ['penalty' => 0])
            ->assertSessionHas('error', function (string $message): bool {
                return str_contains($message, 'policy diskon') && str_contains($message, 'belum dikonfigurkan');
            });

        // Repo saat ini tidak membuat settlement sama sekali di jalur ini.
        $this->assertSame(0, ShmLoanSettlement::query()->where('shm_loan_id', $loan2->id)->count());
    }

    // --------------------------------------------------------------------
    // 4. Hanya payment VERIFIED yang boleh jadi gate completion
    //    (posted/recorded saja TIDAK cukup)
    // --------------------------------------------------------------------

    public function test_payment_posted_without_verification_cannot_complete(): void
    {
        $loan = $this->activeLoanOf($this->user);
        $this->scheduleWithDueAndFuture($loan);
        $this->policy(1, 3, '10.00');

        $settlement = $this->requested($loan);
        $this->actingAs($this->user)->post(route('shm.loans.pelunasan.approve', $loan));

        $payment = $this->paymentFor($loan, (string) $settlement->settlement_amount);

        // Cukup POST pembayaran saja (belum direkam ke settlement) lalu
        // mencoba penyelesaian.
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.complete', $loan))
            ->assertSessionHas('error');

        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->refresh()->status);
        $this->assertSame(0, AuditLog::query()->where('action', 'shm.loan.settled')->count());
    }

    public function test_payment_recorded_but_not_verified_cannot_complete_loop(): void
    {
        $loan = $this->activeLoanOf($this->user);
        $this->scheduleWithDueAndFuture($loan);
        $this->policy(1, 3, '10.00');

        $settlement = $this->requested($loan);
        $this->actingAs($this->user)->post(route('shm.loans.pelunasan.approve', $loan));

        $payment = $this->paymentFor($loan, (string) $settlement->settlement_amount);
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.payment', $loan), ['payment_id' => $payment->id])
            ->assertSessionHas('success');

        $this->assertSame(ShmLoanSettlement::STATUS_PAYMENT_RECORDED, $settlement->refresh()->status);
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->refresh()->status);

        // Gagal complete sebelum verifikasi.
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.complete', $loan))
            ->assertSessionHas('error', function (string $message): bool {
                return str_contains($message, 'payment_verified');
            });

        $this->assertSame(ShmLoanSettlement::STATUS_PAYMENT_RECORDED, $settlement->refresh()->status);
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->refresh()->status);
        $this->assertSame(0, AuditLog::query()->where('action', 'shm.loan.settled')->count());
    }

    // --------------------------------------------------------------------
    // 5. Penutupan pinjaman hanya boleh lewat settlement completion sah
    // --------------------------------------------------------------------

    public function test_approved_without_payment_cannot_close_loan(): void
    {
        $loan = $this->activeLoanOf($this->user);
        $this->scheduleWithDueAndFuture($loan);
        $this->policy(1, 3, '10.00');

        $settlement = $this->requested($loan);
        $this->actingAs($this->user)->post(route('shm.loans.pelunasan.approve', $loan));

        $this->assertSame(ShmLoanSettlement::STATUS_APPROVED, $settlement->refresh()->status);
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->refresh()->status);
        $this->assertSame(0, AuditLog::query()->where('action', 'shm.loan.closed')->count());
    }

    public function test_completed_settlement_idempotent_does_not_duplicate_closure_audit(): void
    {
        $loan = $this->activeLoanOf($this->user);
        $this->scheduleWithDueAndFuture($loan);
        $this->policy(1, 3, '10.00');

        $settlement = $this->requested($loan);
        $this->actingAs($this->user)->post(route('shm.loans.pelunasan.approve', $loan));

        $payment = $this->paymentFor($loan, (string) $settlement->settlement_amount);
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.payment', $loan), ['payment_id' => $payment->id]);
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.payment.verify', $loan));
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.complete', $loan));

        $this->assertSame(1, AuditLog::query()->where('action', 'shm.loan.settled')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'shm.loan.closed')->count());

        // Retry completion tidak menambah audit.
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.complete', $loan));

        $this->assertSame(1, AuditLog::query()->where('action', 'shm.loan.settled')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'shm.loan.closed')->count());
        $this->assertSame(1, ShmLoanSettlement::query()->where('shm_loan_id', $loan->id)->where('status', ShmLoanSettlement::STATUS_COMPLETED)->count());
    }

    public function test_closure_audit_always_carries_settlement_reference(): void
    {
        $loan = $this->activeLoanOf($this->user);
        $this->scheduleWithDueAndFuture($loan);
        $this->policy(1, 3, '10.00');

        $settlement = $this->requested($loan);
        $this->actingAs($this->user)->post(route('shm.loans.pelunasan.approve', $loan));

        $payment = $this->paymentFor($loan, (string) $settlement->settlement_amount);
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.payment', $loan), ['payment_id' => $payment->id]);
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.payment.verify', $loan));
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.complete', $loan));

        $closedAudit = AuditLog::query()
            ->where('action', 'shm.loan.closed')
            ->where('object_id', $loan->id)
            ->firstOrFail();

        $this->assertIsArray($closedAudit->changes);
        $this->assertArrayHasKey('settlement_reference', $closedAudit->changes);
        $this->assertSame($settlement->settlement_reference, $closedAudit->changes['settlement_reference']);
    }

    // --------------------------------------------------------------------
    // 6. Rejected settlement tidak menutup pinjaman & tidak posting
    // --------------------------------------------------------------------

    public function test_rejected_settlement_does_not_close_loan_and_allows_resubmission(): void
    {
        $loan = $this->activeLoanOf($this->user);
        $this->scheduleWithDueAndFuture($loan);
        $this->policy(1, 3, '10.00');

        $first = $this->requested($loan);
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.reject', $loan), ['reason' => 'Coba lagi nanti.'])
            ->assertSessionHas('success');

        $this->assertSame(ShmLoanSettlement::STATUS_REJECTED, $first->refresh()->status);
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->refresh()->status);
        $this->assertSame(0, AuditLog::query()->where('action', 'shm.loan.closed')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'shm.loan.settled')->count());

        // Rejection memungkinkan pengajuan ulang (baris baru).
        $second = $this->requested($loan);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(ShmLoanSettlement::STATUS_PENDING, $second->status);
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->refresh()->status);
    }

    // --------------------------------------------------------------------
    // 7. Overpayment path: dokumentasi perilaku aktual T89D
    // --------------------------------------------------------------------

    public function test_overpayment_completion_currently_blocked_if_no_member_relationship(): void
    {
        // Catatan T89D: test ini tidak mencoba "memperbaiki" overpayment.
        // Ia hanya membuktikan dan mendokumentasikan perilaku aktual saat ini
        // jika service memang masih memblokir completion karena breaker
        // hubungan member → Simpanan Pokok.
        $loan = $this->activeLoanOf($this->user);
        $this->scheduleWithDueAndFuture($loan);
        $this->policy(1, 3, '10.00');

        $settlement = $this->requested($loan);
        $this->actingAs($this->user)->post(route('shm.loans.pelunasan.approve', $loan));

        $payment = $this->paymentFor($loan, '66000000');

        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.payment', $loan), ['payment_id' => $payment->id]);
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.payment.verify', $loan));

        $response = $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.complete', $loan));

        // Jika masih diblokir, ini adalah kondisi aktual yang harus dilaporkan.
        $response->assertSessionHas('error');
        $response->assertSessionHas('error', function (string $message): bool {
            return str_contains($message, 'Simpanan Pokok')
                && str_contains($message, 'BLOCKER BUSINESS DATA')
                && str_contains($message, 'konversi/pembuatan member dilarang');
        });

        $this->assertSame(ShmLoanSettlement::STATUS_PAYMENT_VERIFIED, $settlement->refresh()->status);
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->refresh()->status);
        $this->assertSame(0, AuditLog::query()->where('action', 'shm.loan.settled')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'shm.loan.closed')->count());
    }
}
