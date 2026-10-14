<?php

namespace Tests\Feature;

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
 * T89D — Settlement contract enforcement + regression protection (1).
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
 * eksplisit oleh service, test mendokumentasikan perilaku aktual tersebut.
 */
class ShmSettlementContractEnforcementATest extends TestCase
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
            'name' => 't89d-a-'.Str::uuid(),
            'display_name' => 'T89D-A Tester',
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
            'application_number' => 'PENGAJ-T89D-A-'.Str::upper(Str::random(10)),
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

    // --------------------------------------------------------------------
    // 1. Discount eligibility: <= 50jt tidak dapat discount
    // --------------------------------------------------------------------

    public function test_loan_at_or_below_threshold_has_no_discount_even_if_policy_exists(): void
    {
        $loan = $this->activeLoanOf($this->user, '50000000');
        $this->scheduleWithDueAndFuture($loan);
        $this->policy(1, 3, '20.00');

        $settlement = $this->requested($loan);

        $this->assertFalse($settlement->discount_eligible);
        $this->assertFalse($settlement->discount_applied);
        $this->assertSame('0.00', (string) $settlement->discount_amount);
        $this->assertSame('1500000.00', (string) $settlement->remaining_interest_amount);
        $this->assertSame('13000000.00', (string) $settlement->settlement_amount);
    }

    public function test_loan_below_threshold_has_no_discount_even_with_future_interest(): void
    {
        $loan = $this->activeLoanOf($this->user, '10000000');
        $this->scheduleWithDueAndFuture($loan);
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

        $response = $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.store', $loan), ['penalty' => 0]);

        $response->assertSessionHas('error', function (string $message): bool {
            return str_contains($message, 'policy diskon')
                && str_contains($message, 'belum dikonfigurkan')
                && str_contains($message, '50.000.000');
        });

        $this->assertSame(0, ShmLoanSettlement::query()->count());
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->refresh()->status);
    }

    public function test_eligible_loan_with_policy_but_no_remaining_future_interest_can_still_be_requested(): void
    {
        $loan = $this->activeLoanOf($this->user);
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

        $this->assertTrue($settlement->discount_eligible);
        $this->assertFalse($settlement->discount_applied);
        $this->assertSame('0.00', (string) $settlement->discount_amount);
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
        $this->assertSame('375000.00', (string) $settlement->discount_amount);
        $this->assertSame('25.00', (string) $settlement->discount_percent);

        $this->actingAs($this->user)
            ->put(route('shm.loans.pelunasan.policies.update', $policy), [
                'min_remaining_months' => 1,
                'max_remaining_months' => 3,
                'discount_percent' => 35,
                'is_active' => true,
            ])
            ->assertSessionHas('success');

        $settlement->refresh();
        $this->assertSame('25.00', (string) $settlement->discount_percent);
        $this->assertSame('375000.00', (string) $settlement->discount_amount);
        $this->assertSame('1125000.00', (string) $settlement->interest_after_discount_amount);

        $loan2 = $this->activeLoanOf($this->user);
        $this->scheduleWithDueAndFuture($loan2);
        $settlement2 = $this->requested($loan2);

        $this->assertSame('35.00', (string) $settlement2->discount_percent);
        $this->assertSame('525000.00', (string) $settlement2->discount_amount);
    }

    public function test_snapshot_discount_on_existing_settlement_does_not_change_after_policy_deactivated(): void
    {
        $loan = $this->activeLoanOf($this->user);
        $this->scheduleWithDueAndFuture($loan);
        $policy = $this->policy(1, 3, '20.00');

        $settlement = $this->requested($loan);
        $this->assertSame('300000.00', (string) $settlement->discount_amount);

        // Nonaktifkan policy lewat master.
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.policies.toggle', $policy))
            ->assertSessionHas('success');

        // Settlement yang sudah dibuat tetap menggunakan snapshot.
        $settlement->refresh();
        $this->assertTrue($settlement->discount_applied);
        $this->assertSame('20.00', (string) $settlement->discount_percent);
        $this->assertSame('300000.00', (string) $settlement->discount_amount);

        // Pengajuan BARU pada pinjaman kedua sekarang gagal karena
        // policy aktif tidak tersedia.
        $loan2 = $this->activeLoanOf($this->user);
        $this->scheduleWithDueAndFuture($loan2);

        $response = $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.store', $loan2), ['penalty' => 0]);

        $response->assertSessionHas('error', function (string $message): bool {
            return str_contains($message, 'policy diskon')
                && str_contains($message, 'belum dikonfigurkan');
        });

        $this->assertFalse(ShmLoanSettlement::query()->where('shm_loan_id', $loan2->id)->exists());

        $loan3 = $this->activeLoanOf($this->user);
        $this->scheduleWithDueAndFuture($loan3);

        $response3 = $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.store', $loan3), ['penalty' => 0]);

        $response3->assertSessionHas('error', function (string $message): bool {
            return str_contains($message, 'policy diskon')
                && str_contains($message, 'belum dikonfigurkan');
        });

        $this->assertFalse(ShmLoanSettlement::query()->where('shm_loan_id', $loan3->id)->exists());
    }
}
