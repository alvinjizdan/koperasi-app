<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Member;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SavingProduct;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanInstallment;
use App\Models\ShmLoanPayment;
use App\Models\ShmLoanSettlement;
use App\Models\User;
use App\Services\Shm\ShmLoanPaymentService;
use App\Services\Shm\ShmLoanSettlementService;
use App\Services\Shm\ShmSavingsIntegrationService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * T89E — Penyelesaian settlement dengan OVERPAYMENT → Koperasi Simpanan Pokok.
 *
 * Fixture tetap (angsuran 2x, plafon Rp30.000.000 ≤ threshold diskon):
 * - outstanding principal  Rp30.000.000
 * - bunga jatuh tempo       Rp  500.000
 * - bunga masa depan        Rp  500.000 (tanpa diskon: tidak eligible)
 * - settlement amount       Rp31.000.000
 *
 * Mengunci kontrak T89E:
 * - AC1  overpayment = payment − settlement amount.
 * - AC2  posting ke Simpanan Pokok koperasi existing (bukan SHM Savings).
 * - AC3  penerima berasal dari ShmCustomer.member_id (bukan input frontend).
 * - AC4  tidak ada pembuatan Member otomatis.
 * - AC5  tidak ada SHM Savings.
 * - AC6  overpayment tidak mengurangi principal pinjaman.
 * - AC7  posting idempoten (retry tidak menggandakan transaksi/audit).
 * - AC8  payment wajib verified sebelum completion.
 * - AC9  pinjaman ditutup tepat satu kali.
 * - AC10 settlement rejected tidak memposting overpayment.
 * - AC11 hubungan Member hilang/tidak valid/nonaktif memblokir completion.
 * - AC12 kegagalan posting membatalkan completion (tanpa commit parsial).
 * - AC13 discount/penalty/approval/payment rule lama tidak berubah.
 * - AC14 perilaku payment/allocation lama tidak berubah.
 */
class ShmSettlementOverpaymentCompletionTest extends TestCase
{
    use RefreshDatabase;

    /** Kewajiban pelunasan fixture T89E (lihat docblock). */
    private const SETTLEMENT_AMOUNT = 31_000_000;

    /** Pembayaran fixture dengan lebih bayar Rp1.000.000. */
    private const OVERPAYMENT_PAYMENT = 32_000_000;

    private const OVERPAYMENT = 1_000_000;

    private User $user;

    private SavingProduct $principalProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->user = User::factory()->create(['email_verified_at' => now()]);

        $role = Role::query()->create([
            'name' => 't89e-'.Str::uuid(),
            'display_name' => 'T89E Tester',
            'is_system' => false,
        ]);
        $role->permissions()->attach(
            Permission::query()->whereIn('name', [
                'shm.view',
                'shm.payment.create',
                'shm.settlement.create',
            ])->pluck('id')
        );
        $this->user->roles()->attach($role->id);

        $this->principalProduct = SavingProduct::create([
            'code' => 'SP-T89E',
            'name' => 'Simpanan Pokok',
            'category' => SavingProduct::CATEGORY_PRINCIPAL,
            'description' => 'Simpanan Pokok Anggota',
            'is_active' => true,
        ]);
    }

    // ==================================================================
    // FIXTURE HELPERS
    // ==================================================================

    private function createMember(array $attributes = []): Member
    {
        return Member::create(array_merge([
            'member_number' => 'ANG-T89E-'.Str::upper(Str::random(6)),
            'full_name' => 'Anggota T89E '.Str::random(4),
            'identity_number' => '3201'.random_int(100000000000, 999999999999),
            'phone' => '08123456789',
            'address' => 'Jl. Koperasi No. 10',
            'joined_at' => now()->toDateString(),
            'status' => 'active',
        ], $attributes));
    }

    private function customerFor(?Member $member): ShmCustomer
    {
        return ShmCustomer::factory()->create(
            $member === null ? [] : ['member_id' => $member->id]
        );
    }

    /**
     * Pinjaman aktif + jadwal 2 angsuran (1 tempo lalu, 1 masa depan).
     */
    private function activeLoanFor(ShmCustomer $customer): ShmLoan
    {
        $application = ShmLoanApplication::query()->create([
            'application_number' => 'PENGAJ-T89E-'.Str::upper(Str::random(10)),
            'shm_customer_id' => $customer->id,
            'application_date' => now()->toDateString(),
            'principal' => '30000000',
            'tenor_months' => 2,
            'first_due_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'marketing_id' => $this->user->id,
            'status' => 'approved',
        ]);

        $loan = ShmLoan::factory()->create([
            'shm_customer_id' => $customer->id,
            'shm_loan_application_id' => $application->id,
            'principal' => '30000000',
            'tenor_months' => 2,
        ]);

        foreach ([now()->subMonth()->startOfMonth(), now()->addMonth()->startOfMonth()] as $index => $due) {
            $loan->installments()->create([
                'installment_number' => $index + 1,
                'due_date' => $due->toDateString(),
                'principal_amount' => 15_000_000,
                'interest_amount' => 500_000,
                'total_amount' => 15_500_000,
                'paid_amount' => 0,
                'paid_interest_amount' => 0,
                'paid_principal_amount' => 0,
                'remaining_amount' => 15_500_000,
                'status' => ShmLoanInstallment::STATUS_SCHEDULED,
            ]);
        }

        return $loan;
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

    /**
     * Bawa settlement sampai payment_verified dengan nominal pembayaran tertentu.
     */
    private function verifiedSettlement(ShmLoan $loan, string $paymentAmount): ShmLoanSettlement
    {
        $settlement = $this->requested($loan);

        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.approve', $loan))
            ->assertSessionHas('success');

        $payment = $this->paymentFor($loan, $paymentAmount);

        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.payment', $loan), ['payment_id' => $payment->id])
            ->assertSessionHas('success');

        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.payment.verify', $loan))
            ->assertSessionHas('success');

        return $settlement->refresh();
    }

    private function principalSavingsAccountOf(Member $member): ?SavingsAccount
    {
        return SavingsAccount::query()
            ->where('member_id', $member->id)
            ->whereHas('savingProduct', function ($query): void {
                $query->where('category', SavingProduct::CATEGORY_PRINCIPAL);
            })
            ->first();
    }

    private function assertSettlementStillVerifiedAndLoanActive(ShmLoan $loan, ShmLoanSettlement $settlement): void
    {
        $this->assertSame(ShmLoanSettlement::STATUS_PAYMENT_VERIFIED, $settlement->refresh()->status);
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->refresh()->status);
        $this->assertSame(0, AuditLog::query()->where('action', 'shm.loan.settled')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'shm.loan.closed')->count());
    }

    // ==================================================================
    // A. EXACT PAYMENT
    // ==================================================================

    public function test_exact_payment_completes_without_any_savings_posting(): void
    {
        $member = $this->createMember();
        $loan = $this->activeLoanFor($this->customerFor($member));
        $settlement = $this->verifiedSettlement($loan, (string) self::SETTLEMENT_AMOUNT);

        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.complete', $loan))
            ->assertSessionHas('success');

        $settlement->refresh();
        $loan->refresh();

        $this->assertSame(ShmLoanSettlement::STATUS_COMPLETED, $settlement->status);
        $this->assertSame('0.00', (string) $settlement->overpayment_amount);
        $this->assertSame(ShmLoan::STATUS_CLOSED, $loan->status);

        // Tidak ada overpayment → tidak ada posting simpanan apa pun.
        $this->assertSame(0, SavingsTransaction::query()->count());
        $this->assertSame(0, SavingsAccount::query()->count());

        $this->assertSame(1, AuditLog::query()->where('action', 'shm.loan.settled')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'shm.loan.closed')->count());
    }

    // ==================================================================
    // B. OVERPAYMENT → SIMPANAN POKOK
    // ==================================================================

    public function test_overpayment_is_posted_to_source_member_principal_savings(): void
    {
        $member = $this->createMember();
        $loan = $this->activeLoanFor($this->customerFor($member));
        $settlement = $this->verifiedSettlement($loan, (string) self::OVERPAYMENT_PAYMENT);

        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.complete', $loan))
            ->assertSessionHas('success');

        $settlement->refresh();
        $loan->refresh();

        // AC1 — overpayment = payment − settlement amount.
        $this->assertSame(
            self::OVERPAYMENT_PAYMENT - self::SETTLEMENT_AMOUNT,
            (int) $settlement->overpayment_amount,
        );
        $this->assertSame('1000000.00', (string) $settlement->overpayment_amount);
        $this->assertSame(ShmLoanSettlement::STATUS_COMPLETED, $settlement->status);
        $this->assertSame(ShmLoan::STATUS_CLOSED, $loan->status);

        // AC2 + AC5 — posting ke modul Simpanan Koperasi existing (bukan SHM Savings).
        $account = $this->principalSavingsAccountOf($member);
        $this->assertNotNull($account);
        $this->assertEquals(self::OVERPAYMENT, (float) $account->balance);

        $transaction = SavingsTransaction::query()->sole();
        $this->assertSame('deposit', $transaction->type);
        $this->assertEquals(self::OVERPAYMENT, (float) $transaction->amount);
        $this->assertSame($account->id, $transaction->savings_account_id);
        $this->assertSame(
            sprintf('shm-settlement-overpayment:%d', $settlement->id),
            $transaction->idempotency_key,
        );

        // AC6 — principal pinjaman tidak berubah.
        $this->assertSame('30000000.00', (string) $loan->principal);
        $this->assertSame('31000000.00', (string) $settlement->settlement_amount);
        $this->assertEquals(
            30_000_000,
            (float) $loan->installments()->sum('principal_amount'),
        );

        // AC9 — audit completion & closure masing-masing tepat satu.
        $this->assertSame(1, AuditLog::query()->where('action', 'shm.loan.settled')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'shm.loan.closed')->count());
    }

    public function test_overpayment_destination_follows_customer_member_id_not_another_member(): void
    {
        $source = $this->createMember();
        $unrelated = $this->createMember();

        // Anggota lain sudah punya rekening Simpanan Pokok agar tidak "penampung diam-diam".
        app(ShmSavingsIntegrationService::class)
            ->deposit($unrelated, SavingProduct::CATEGORY_PRINCIPAL, 50_000, 't89e-unrelated-seed', 'seed', (int) $this->user->id);

        $loan = $this->activeLoanFor($this->customerFor($source));
        $settlement = $this->verifiedSettlement($loan, (string) self::OVERPAYMENT_PAYMENT);

        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.complete', $loan))
            ->assertSessionHas('success');

        // AC3 — penerima diturunkan dari ShmCustomer.member_id.
        $this->assertSame($source->id, $loan->refresh()->customer->member_id);

        $this->assertEquals(self::OVERPAYMENT, (float) $this->principalSavingsAccountOf($source)->balance);
        $this->assertEquals(50_000, (float) $this->principalSavingsAccountOf($unrelated)->balance);

        // AC4 — tidak ada Member baru dibuat otomatis.
        $this->assertSame(2, Member::query()->count());

        $this->assertSame('shm-settlement-overpayment:'.$settlement->id, SavingsTransaction::query()
            ->where('savings_account_id', $this->principalSavingsAccountOf($source)->id)
            ->sole()
            ->idempotency_key);
    }

    // ==================================================================
    // C. MEMBER RELATIONSHIP
    // ==================================================================

    public function test_missing_member_relationship_blocks_completion(): void
    {
        $loan = $this->activeLoanFor($this->customerFor(null));
        $settlement = $this->verifiedSettlement($loan, (string) self::OVERPAYMENT_PAYMENT);

        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.complete', $loan))
            ->assertSessionHas('error', function (string $message): bool {
                $this->assertStringContainsString('Simpanan Pokok', $message);
                $this->assertStringContainsString('BLOCKER BUSINESS DATA', $message);
                $this->assertStringContainsString('konversi/pembuatan member dilarang', $message);

                return true;
            });

        $this->assertSettlementStillVerifiedAndLoanActive($loan, $settlement);
        $this->assertSame(0, SavingsTransaction::query()->count());
        $this->assertSame(0, SavingsAccount::query()->count());
        $this->assertSame('30000000.00', (string) $loan->refresh()->principal);
    }

    public function test_inactive_member_blocks_completion_and_creates_no_savings_account(): void
    {
        $member = $this->createMember(['status' => 'inactive']);
        $loan = $this->activeLoanFor($this->customerFor($member));
        $settlement = $this->verifiedSettlement($loan, (string) self::OVERPAYMENT_PAYMENT);

        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.complete', $loan))
            ->assertSessionHas('error', function (string $message): bool {
                $this->assertStringContainsString('berstatus "inactive"', $message);
                $this->assertStringContainsString('Simpanan Pokok', $message);

                return true;
            });

        $this->assertSettlementStillVerifiedAndLoanActive($loan, $settlement);
        $this->assertSame(0, SavingsTransaction::query()->count());
        // Rekening pun tidak boleh tersisa (rollback penuh).
        $this->assertSame(0, SavingsAccount::query()->count());
    }

    public function test_member_with_unrecognized_status_blocks_completion(): void
    {
        $member = $this->createMember(['status' => 'suspended']);
        $loan = $this->activeLoanFor($this->customerFor($member));
        $settlement = $this->verifiedSettlement($loan, (string) self::OVERPAYMENT_PAYMENT);

        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.complete', $loan))
            ->assertSessionHas('error');

        $this->assertSettlementStillVerifiedAndLoanActive($loan, $settlement);
        $this->assertSame(0, SavingsTransaction::query()->count());
        $this->assertSame(0, SavingsAccount::query()->count());
    }

    // ==================================================================
    // D. IDEMPOTENCY
    // ==================================================================

    public function test_repeated_completion_never_duplicates_savings_transaction_or_closure_audit(): void
    {
        $member = $this->createMember();
        $loan = $this->activeLoanFor($this->customerFor($member));
        $settlement = $this->verifiedSettlement($loan, (string) self::OVERPAYMENT_PAYMENT);

        foreach ([1, 2, 3] as $attempt) {
            $this->actingAs($this->user)
                ->post(route('shm.loans.pelunasan.complete', $loan))
                ->assertSessionHas('success');

            $this->assertSame(1, SavingsTransaction::query()->count(), "percobaan ke-{$attempt}");
            $this->assertSame(1, AuditLog::query()->where('action', 'shm.loan.settled')->count(), "percobaan ke-{$attempt}");
            $this->assertSame(1, AuditLog::query()->where('action', 'shm.loan.closed')->count(), "percobaan ke-{$attempt}");
            $this->assertSame(1, ShmLoanSettlement::query()->where('status', ShmLoanSettlement::STATUS_COMPLETED)->count());
            $this->assertEquals(
                self::OVERPAYMENT,
                (float) $this->principalSavingsAccountOf($member)->balance,
                "percobaan ke-{$attempt}",
            );
        }

        $this->assertSame(
            sprintf('shm-settlement-overpayment:%d', $settlement->id),
            SavingsTransaction::query()->sole()->idempotency_key,
        );
    }

    // ==================================================================
    // E. PAYMENT VERIFICATION GATE
    // ==================================================================

    public function test_completion_requires_verified_payment(): void
    {
        // E1 — baru di-approve, pembayaran belum dibuat.
        $member = $this->createMember();
        $loan = $this->activeLoanFor($this->customerFor($member));
        $settlement = $this->requested($loan);

        $this->actingAs($this->user)->post(route('shm.loans.pelunasan.approve', $loan))->assertSessionHas('success');
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.complete', $loan))
            ->assertSessionHas('error', function (string $message): bool {
                $this->assertStringContainsString('payment_verified', $message);

                return true;
            });
        $this->assertSame(ShmLoanSettlement::STATUS_APPROVED, $settlement->refresh()->status);
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->refresh()->status);
        $this->assertSame(0, AuditLog::query()->where('action', 'shm.loan.settled')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'shm.loan.closed')->count());

        // E2 — pembayaran dibuat (posted) tapi belum ditautkan.
        $payment = $this->paymentFor($loan, (string) self::OVERPAYMENT_PAYMENT);
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.complete', $loan))
            ->assertSessionHas('error');
        $this->assertSame(ShmLoanSettlement::STATUS_APPROVED, $settlement->refresh()->status);

        // E3 — ditautkan (payment_recorded) tapi belum diverifikasi.
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.payment', $loan), ['payment_id' => $payment->id])
            ->assertSessionHas('success');
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.complete', $loan))
            ->assertSessionHas('error', function (string $message): bool {
                $this->assertStringContainsString('payment_verified', $message);

                return true;
            });
        $this->assertSame(ShmLoanSettlement::STATUS_PAYMENT_RECORDED, $settlement->refresh()->status);
        $this->assertSame(0, SavingsTransaction::query()->count());

        // E4 — diverifikasi → completion diizinkan.
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.payment.verify', $loan))
            ->assertSessionHas('success');
        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.complete', $loan))
            ->assertSessionHas('success');
        $this->assertSame(ShmLoanSettlement::STATUS_COMPLETED, $settlement->refresh()->status);
        $this->assertSame(1, SavingsTransaction::query()->count());
    }

    public function test_pending_settlement_cannot_jump_to_completed(): void
    {
        $member = $this->createMember();
        $loan = $this->activeLoanFor($this->customerFor($member));
        $settlement = $this->requested($loan);

        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.complete', $loan))
            ->assertSessionHas('error');

        $this->assertSame(ShmLoanSettlement::STATUS_PENDING, $settlement->refresh()->status);
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->refresh()->status);
        $this->assertSame(0, SavingsTransaction::query()->count());
    }

    // ==================================================================
    // F. ROLLBACK
    // ==================================================================

    public function test_savings_posting_failure_keeps_settlement_incomplete_and_loan_active(): void
    {
        $member = $this->createMember();
        $loan = $this->activeLoanFor($this->customerFor($member));
        $settlement = $this->verifiedSettlement($loan, (string) self::OVERPAYMENT_PAYMENT);

        // Simpanan Pokok tidak tersedia → posting gagal.
        SavingProduct::query()->delete();

        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.complete', $loan))
            ->assertSessionHas('error', function (string $message): bool {
                $this->assertStringContainsString('SAVINGS_PRODUCT_CONFIGURATION_BLOCKER', $message);

                return true;
            });

        $this->assertSettlementStillVerifiedAndLoanActive($loan, $settlement);
        $this->assertSame(0, SavingsTransaction::query()->count());
        $this->assertSame(0, SavingsAccount::query()->count());
        $this->assertSame('30000000.00', (string) $loan->refresh()->principal);
        // Angsuran belum dis settlementkan.
        $this->assertSame(
            0,
            $loan->installments()->where('status', ShmLoanInstallment::STATUS_PAID)->count(),
        );
    }

    public function test_failure_after_savings_posting_rolls_back_the_savings_transaction(): void
    {
        $member = $this->createMember();
        $loan = $this->activeLoanFor($this->customerFor($member));
        $settlement = $this->verifiedSettlement($loan, (string) self::OVERPAYMENT_PAYMENT);

        // Fault injection: gagal pada tahap settlementkan angsuran, yaitu
        // SETELAH posting Simpanan Pokok dan SEBELUM settlement/pinjaman berubah.
        ShmLoanInstallment::saving(function (): void {
            throw new RuntimeException('T89E fault injection: tahap penyelesaian gagal setelah posting simpanan.');
        });

        try {
            app(ShmLoanSettlementService::class)->complete($loan, (int) $this->user->id);
            $this->fail('Completion seharusnya gagal pada fault injection.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('T89E fault injection', $exception->getMessage());
        }

        // AC12 — tidak ada commit parsial: posting simpanan ikut ter rollback.
        $this->assertSettlementStillVerifiedAndLoanActive($loan, $settlement);
        $this->assertSame(0, SavingsTransaction::query()->count());
        $this->assertSame(0, SavingsAccount::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'savings.transaction.deposit')->count());
        $this->assertEquals(31_000_000, (float) $loan->installments()->sum('remaining_amount'));
    }

    // ==================================================================
    // G. REJECTED SETTLEMENT
    // ==================================================================

    public function test_rejected_settlement_never_posts_overpayment(): void
    {
        $member = $this->createMember();
        $loan = $this->activeLoanFor($this->customerFor($member));

        $settlement = $this->requested($loan);

        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.reject', $loan), ['reason' => 'Data belum lengkap.'])
            ->assertSessionHas('success');

        $this->assertSame(ShmLoanSettlement::STATUS_REJECTED, $settlement->refresh()->status);

        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.complete', $loan))
            ->assertSessionHas('error');

        $this->assertSame(ShmLoanSettlement::STATUS_REJECTED, $settlement->refresh()->status);
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->refresh()->status);
        $this->assertSame(0, SavingsTransaction::query()->count());
        $this->assertSame(0, SavingsAccount::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'shm.loan.settled')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'shm.loan.closed')->count());
    }

    // ==================================================================
    // H. PRINCIPAL INVARIANT
    // ==================================================================

    public function test_overpayment_never_reduces_loan_principal_or_schedule(): void
    {
        $member = $this->createMember();
        $loan = $this->activeLoanFor($this->customerFor($member));

        $settlement = $this->verifiedSettlement($loan, (string) self::OVERPAYMENT_PAYMENT);
        $settlementAmountBefore = (string) $settlement->settlement_amount;
        $scheduleColumns = ['installment_number', 'principal_amount', 'interest_amount', 'total_amount'];
        $scheduleBefore = $loan->installments()->orderBy('installment_number')->get($scheduleColumns)->toArray();

        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.complete', $loan))
            ->assertSessionHas('success');

        $loan->refresh();
        $settlement->refresh();

        // AC6 — pokok, bunga, dan komponen settlement tidak berubah.
        $this->assertSame('30000000.00', (string) $loan->principal);
        $this->assertSame($settlementAmountBefore, (string) $settlement->settlement_amount);
        $this->assertSame('1000000.00', (string) $settlement->overpayment_amount);
        $this->assertSame(
            $scheduleBefore,
            $loan->installments()->orderBy('installment_number')->get($scheduleColumns)->toArray(),
        );

        // Pokok angsuran tetap Rp30.000.000 (hanya status yang berubah menjadi paid).
        $this->assertEquals(30_000_000, (float) $loan->installments()->sum('principal_amount'));

        // Tidak ada alokasi pembayaran baru dari settlement (AC14).
        $this->assertSame(0, DB::table('shm_loan_payment_allocations')->count());
    }
}
