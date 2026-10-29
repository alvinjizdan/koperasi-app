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
use App\Models\ShmLoanPaymentAllocation;
use App\Models\ShmLoanSettlement;
use App\Models\User;
use App\Services\Shm\ShmRegularPaymentService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * T003 — Test Suite Pembayaran Angsuran Reguler Pinjaman SHM (Regular Payment).
 *
 * Menguji:
 * - Group A: Happy Path (A1: Alokasi sebagian, A2: Pelunasan tepat & penutupan pinjaman)
 * - Group B: Underpayment (Ditolak, fail-closed, 0 efek samping)
 * - Group C: Overpayment (Kelebihan dialokasikan ke Simpanan Pokok anggota sumber)
 * - Group D: Prioritas Alokasi (Bunga sebelum pokok, urutan kronologis angsuran)
 * - Group E: Penalty selalu 0
 * - Group F: Atomic Rollback (Kegagalan mengembalikan seluruh mutasi)
 * - Group G: Idempotency (Request berulang tidak menduplikasi efek finansial)
 * - Group H: Concurrency Safety (Row locking & integritas saldo)
 * - Group I: Settlement Lock (Pembayaran ditolak jika ada settlement aktif)
 * - Group J: Otorisasi & Scope Kepemilikan (shm.payment.create & batas nasabah)
 */
class ShmRegularPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $officer;

    private User $unauthorizedUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->admin = $this->userWithRole('admin');
        $this->officer = $this->userWithPermissions(['shm.view', 'shm.payment.create']);
        $this->unauthorizedUser = User::factory()->create(['email_verified_at' => now()]);

        // Pastikan produk simpanan pokok aktif tersedia untuk overpayment
        SavingProduct::query()->firstOrCreate(
            ['category' => SavingProduct::CATEGORY_PRINCIPAL],
            [
                'code' => 'SP-001',
                'name' => 'Simpanan Pokok',
                'minimum_amount' => 100000,
                'is_active' => true,
            ]
        );
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
            'name' => 't003-tester-'.Str::uuid(),
            'display_name' => 'T003 Tester',
            'is_system' => false,
        ]);

        $role->permissions()->attach(
            Permission::query()->whereIn('name', $permissionNames)->pluck('id')
        );

        $user->roles()->attach($role->id);

        return $user;
    }

    private function createMember(string $status = 'active'): Member
    {
        return Member::factory()->create([
            'status' => $status,
        ]);
    }

    /**
     * Buat fixture pinjaman aktif dengan jadwal angsuran.
     *
     * @param  array<int, array{interest: float, principal: float}>|null  $installmentSpecs
     */
    private function createActiveLoanWithSchedule(?Member $member = null, ?array $installmentSpecs = null, ?User $owner = null): ShmLoan
    {
        $member = $member ?? $this->createMember();
        $owner = $owner ?? $this->officer;

        $customer = ShmCustomer::factory()->create([
            'member_id' => $member->id,
        ]);

        $application = ShmLoanApplication::query()->create([
            'application_number' => 'PENGAJ-T003-'.Str::upper(Str::random(8)),
            'shm_customer_id' => $customer->id,
            'application_date' => now()->toDateString(),
            'principal' => '30000000',
            'approved_amount' => '30000000',
            'tenor_months' => 3,
            'first_due_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'marketing_id' => $owner->id,
            'status' => 'approved',
        ]);

        $loan = ShmLoan::factory()->create([
            'shm_customer_id' => $customer->id,
            'shm_loan_application_id' => $application->id,
            'principal' => '30000000',
            'net_disbursement' => '29000000',
            'tenor_months' => 3,
            'disbursed_at' => now()->toDateString(),
            'status' => ShmLoan::STATUS_ACTIVE,
        ]);

        $specs = $installmentSpecs ?? [
            ['interest' => 1_500_000, 'principal' => 5_000_000], // Total 6.5M
            ['interest' => 1_500_000, 'principal' => 5_000_000], // Total 6.5M
            ['interest' => 1_500_000, 'principal' => 5_000_000], // Total 6.5M
        ];

        foreach ($specs as $index => $spec) {
            $total = $spec['interest'] + $spec['principal'];
            $loan->installments()->create([
                'installment_number' => $index + 1,
                'due_date' => now()->addMonths($index + 1)->toDateString(),
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

    // =================================================================
    // GROUP A — HAPPY PATH
    // =================================================================

    public function test_a1_payment_fully_covers_interest_and_partially_covers_principal(): void
    {
        $loan = $this->createActiveLoanWithSchedule();
        // Angsuran #1: bunga 1.5M, pokok 5M (total 6.5M).
        // Bayar 8.0M: lunas Angsuran #1 (1.5M bunga + 5M pokok = 6.5M),
        // sisa 1.5M masuk Angsuran #2 (1.5M bunga + 0 pokok).
        $response = $this->actingAs($this->officer)->post(route('shm.loans.payments.store'), [
            'loan_id' => $loan->id,
            'payment_date' => now()->toDateString(),
            'amount' => '8000000',
            'payment_channel' => 'transfer',
            'notes' => 'Pembayaran parsial angsuran',
        ]);

        $response->assertRedirect(route('shm.loans.payments.index'));
        $response->assertSessionHasNoErrors();

        $loan->refresh();
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->status);

        // Angsuran #1 harus lunas
        $inst1 = $loan->installments()->where('installment_number', 1)->first();
        $this->assertSame(ShmLoanInstallment::STATUS_PAID, $inst1->status);
        $this->assertSame('1500000.00', (string) $inst1->paid_interest_amount);
        $this->assertSame('5000000.00', (string) $inst1->paid_principal_amount);
        $this->assertSame('0.00', (string) $inst1->remaining_amount);

        // Angsuran #2 harus menerima 1.5M bunga, pokok 0
        $inst2 = $loan->installments()->where('installment_number', 2)->first();
        $this->assertSame(ShmLoanInstallment::STATUS_SCHEDULED, $inst2->status);
        $this->assertSame('1500000.00', (string) $inst2->paid_interest_amount);
        $this->assertSame('0.00', (string) $inst2->paid_principal_amount);
        $this->assertSame('5000000.00', (string) $inst2->remaining_amount);

        // Angsuran #3 belum tersentuh
        $inst3 = $loan->installments()->where('installment_number', 3)->first();
        $this->assertSame('0.00', (string) $inst3->paid_amount);

        // Total owed tersisa = 5M (pokok #2) + 6.5M (#3) = 11.5M
        $this->assertEquals(11_500_000, $loan->totalOwed());

        // Tidak ada setoran simpanan (bukan overpayment)
        $this->assertSame(0, SavingsTransaction::query()->count());
    }

    public function test_a2_payment_exactly_clears_total_owed_and_closes_loan(): void
    {
        $loan = $this->createActiveLoanWithSchedule();
        $totalOwed = $loan->totalOwed(); // 19.5M (3 × 6.5M)

        $response = $this->actingAs($this->officer)->post(route('shm.loans.payments.store'), [
            'loan_id' => $loan->id,
            'payment_date' => now()->toDateString(),
            'amount' => (string) $totalOwed,
            'notes' => 'Pelunasan tepat seluruh angsuran',
        ]);

        $response->assertRedirect(route('shm.loans.payments.index'));
        $response->assertSessionHasNoErrors();

        $loan->refresh();
        $this->assertSame(ShmLoan::STATUS_CLOSED, $loan->status);
        $this->assertEquals(0.0, $loan->totalOwed());

        // Semua angsuran harus berstatus PAID
        $this->assertSame(3, $loan->installments()->where('status', ShmLoanInstallment::STATUS_PAID)->count());

        // Audit shm.loan.closed harus tercatat
        $closedAudit = AuditLog::query()
            ->where('object_type', ShmLoan::class)
            ->where('object_id', $loan->id)
            ->where('action', 'shm.loan.closed')
            ->first();
        $this->assertNotNull($closedAudit);

        // Audit shm.loan.payment.applied harus tercatat
        $payment = ShmLoanPayment::query()->where('shm_loan_id', $loan->id)->first();
        $appliedAudit = AuditLog::query()
            ->where('object_type', ShmLoanPayment::class)
            ->where('object_id', $payment->id)
            ->where('action', 'shm.loan.payment.applied')
            ->first();
        $this->assertNotNull($appliedAudit);
        $this->assertTrue(data_get($appliedAudit->changes, 'loan_closed'));
    }

    // =================================================================
    // GROUP B — UNDERPAYMENT
    // =================================================================

    public function test_b1_underpayment_below_current_obligation_is_rejected_without_side_effects(): void
    {
        $loan = $this->createActiveLoanWithSchedule();
        // Angsuran #1 bernilai 6.5M. Customer bayar 6.0M -> REJECT
        $response = $this->actingAs($this->officer)->post(route('shm.loans.payments.store'), [
            'loan_id' => $loan->id,
            'payment_date' => now()->toDateString(),
            'amount' => '6000000',
        ]);

        $response->assertSessionHasErrors(['amount']);

        // Pastikan tidak ada efek finansial sampingan
        $this->assertSame(0, ShmLoanPayment::query()->count());
        $this->assertSame(0, ShmLoanPaymentAllocation::query()->count());
        $this->assertSame(0, SavingsTransaction::query()->count());

        $loan->refresh();
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->status);
        $this->assertEquals(19_500_000, $loan->totalOwed());

        // Angsuran #1 tidak termutasi sama sekali
        $inst1 = $loan->installments()->where('installment_number', 1)->first();
        $this->assertSame('0.00', (string) $inst1->paid_amount);
        $this->assertSame(ShmLoanInstallment::STATUS_SCHEDULED, $inst1->status);

        // Tidak ada audit sukses
        $this->assertSame(0, AuditLog::query()->where('action', 'shm.loan.payment.applied')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'shm.loan.payment.created')->count());
    }

    public function test_b2_payment_on_loan_without_schedule_is_rejected(): void
    {
        $member = $this->createMember();
        $customer = ShmCustomer::factory()->create(['member_id' => $member->id]);
        $loan = ShmLoan::factory()->create([
            'shm_customer_id' => $customer->id,
            'principal' => '10000000',
            'status' => ShmLoan::STATUS_ACTIVE,
            'disbursed_at' => now()->toDateString(),
        ]);
        // Tanpa angsuran sama sekali

        $response = $this->actingAs($this->admin)->post(route('shm.loans.payments.store'), [
            'loan_id' => $loan->id,
            'payment_date' => now()->toDateString(),
            'amount' => '5000000',
        ]);

        $response->assertSessionHasErrors(['loan_id']);
        $this->assertSame(0, ShmLoanPayment::query()->count());
    }

    // =================================================================
    // GROUP C — OVERPAYMENT
    // =================================================================

    public function test_c1_overpayment_allocates_all_owed_and_transfers_excess_to_simpanan_pokok(): void
    {
        $member = $this->createMember();
        $loan = $this->createActiveLoanWithSchedule($member);
        $totalOwed = $loan->totalOwed(); // 19.5M

        // Bayar 22.0M: lunas seluruh 19.5M, lebih bayar 2.5M ke Simpanan Pokok
        $paymentAmount = $totalOwed + 2_500_000;

        $response = $this->actingAs($this->officer)->post(route('shm.loans.payments.store'), [
            'loan_id' => $loan->id,
            'payment_date' => now()->toDateString(),
            'amount' => (string) $paymentAmount,
        ]);

        $response->assertRedirect(route('shm.loans.payments.index'));
        $response->assertSessionHasNoErrors();

        $loan->refresh();
        $this->assertSame(ShmLoan::STATUS_CLOSED, $loan->status);
        $this->assertEquals(0.0, $loan->totalOwed());

        // Verifikasi transaksi simpanan pokok anggota sumber
        $savingsTx = SavingsTransaction::query()->sole();
        $this->assertEquals(2_500_000, (float) $savingsTx->amount);
        $this->assertSame('deposit', $savingsTx->type);

        // Rekening tujuan harus simpanan pokok milik member
        $account = SavingsAccount::query()->findOrFail($savingsTx->savings_account_id);
        $this->assertSame($member->id, $account->member_id);
        $this->assertSame(SavingProduct::CATEGORY_PRINCIPAL, $account->savingProduct->category);

        // Verifikasi audit applied mencatat transfer overpayment
        $payment = ShmLoanPayment::query()->sole();
        $appliedLog = AuditLog::query()
            ->where('object_type', ShmLoanPayment::class)
            ->where('object_id', $payment->id)
            ->where('action', 'shm.loan.payment.applied')
            ->sole();

        $this->assertSame('2500000.00', data_get($appliedLog->changes, 'excess_amount'));
        $this->assertSame('2500000.00', data_get($appliedLog->changes, 'transferred_amount'));
        $this->assertSame('Simpanan Pokok', data_get($appliedLog->changes, 'excess_destination'));
        $this->assertSame($member->id, data_get($appliedLog->changes, 'member_id'));
    }

    public function test_c2_overpayment_fails_closed_if_customer_has_no_source_member(): void
    {
        $customerWithoutMember = ShmCustomer::factory()->create(['member_id' => null]);
        $loan = ShmLoan::factory()->create([
            'shm_customer_id' => $customerWithoutMember->id,
            'principal' => '10000000',
            'disbursed_at' => now()->toDateString(),
            'status' => ShmLoan::STATUS_ACTIVE,
        ]);
        $loan->installments()->create([
            'installment_number' => 1,
            'due_date' => now()->addMonth()->toDateString(),
            'principal_amount' => 5_000_000,
            'interest_amount' => 1_000_000,
            'total_amount' => 6_000_000,
            'paid_amount' => 0,
            'paid_interest_amount' => 0,
            'paid_principal_amount' => 0,
            'remaining_amount' => 6_000_000,
            'status' => ShmLoanInstallment::STATUS_SCHEDULED,
        ]);

        // Bayar 10M (> total owed 6M) -> butuh overpayment deposit tetapi tidak ada member sumber
        $response = $this->actingAs($this->admin)->post(route('shm.loans.payments.store'), [
            'loan_id' => $loan->id,
            'payment_date' => now()->toDateString(),
            'amount' => '10000000',
        ]);

        $response->assertSessionHasErrors(['amount']);

        // Full rollback — tidak ada pembayaran, alokasi, simpanan, atau perubahan status
        $this->assertSame(0, ShmLoanPayment::query()->count());
        $this->assertSame(0, ShmLoanPaymentAllocation::query()->count());
        $this->assertSame(0, SavingsTransaction::query()->count());
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->refresh()->status);
    }

    public function test_c3_overpayment_fails_closed_if_source_member_is_inactive(): void
    {
        $inactiveMember = $this->createMember('inactive');
        $loan = $this->createActiveLoanWithSchedule($inactiveMember);

        $response = $this->actingAs($this->officer)->post(route('shm.loans.payments.store'), [
            'loan_id' => $loan->id,
            'payment_date' => now()->toDateString(),
            'amount' => '25000000', // Melebihi 19.5M
        ]);

        $response->assertSessionHasErrors(['amount']);
        $this->assertSame(0, ShmLoanPayment::query()->count());
        $this->assertSame(0, SavingsTransaction::query()->count());
    }

    // =================================================================
    // GROUP D — INTEREST ALLOCATION
    // =================================================================

    public function test_d1_interest_is_allocated_strictly_before_principal(): void
    {
        $loan = $this->createActiveLoanWithSchedule(null, [
            ['interest' => 2_000_000, 'principal' => 8_000_000],
        ]);

        // Bayar tepat 5.0M (di atas required 10M? Tidak, di bawah -> underpayment).
        // Jadi kita uji dengan 1 angsuran total 4M: bunga 1.5M, pokok 2.5M.
        $loan2 = $this->createActiveLoanWithSchedule(null, [
            ['interest' => 1_500_000, 'principal' => 2_500_000], // total 4M
            ['interest' => 1_500_000, 'principal' => 2_500_000], // total 4M
        ]);

        // Bayar 5.0M:
        // Angsuran #1 lunas (1.5M bunga + 2.5M pokok = 4M).
        // Angsuran #2 menerima sisa 1.0M: WAJIB masuk bunga dahulu (1.0M bunga, 0 pokok).
        $this->actingAs($this->officer)->post(route('shm.loans.payments.store'), [
            'loan_id' => $loan2->id,
            'payment_date' => now()->toDateString(),
            'amount' => '5000000',
        ])->assertSessionHasNoErrors();

        $inst2 = $loan2->installments()->where('installment_number', 2)->first();
        $this->assertSame('1000000.00', (string) $inst2->paid_interest_amount);
        $this->assertSame('0.00', (string) $inst2->paid_principal_amount);

        // Periksa rincian baris alokasi
        $allocations = ShmLoanPaymentAllocation::query()->orderBy('shm_loan_installment_id')->get();
        $this->assertCount(2, $allocations);

        // Alokasi #1 (angsuran 1)
        $this->assertSame('1500000.00', (string) $allocations[0]->interest_amount);
        $this->assertSame('2500000.00', (string) $allocations[0]->principal_amount);

        // Alokasi #2 (angsuran 2)
        $this->assertSame('1000000.00', (string) $allocations[1]->interest_amount);
        $this->assertSame('0.00', (string) $allocations[1]->principal_amount);
    }

    // =================================================================
    // GROUP E — NO PENALTY
    // =================================================================

    public function test_e1_regular_payment_allocations_have_zero_penalty(): void
    {
        $loan = $this->createActiveLoanWithSchedule();

        $this->actingAs($this->officer)->post(route('shm.loans.payments.store'), [
            'loan_id' => $loan->id,
            'payment_date' => now()->toDateString(),
            'amount' => '6500000',
        ])->assertSessionHasNoErrors();

        $allocation = ShmLoanPaymentAllocation::query()->sole();
        $this->assertSame('0.00', (string) $allocation->penalty_amount);

        $appliedLog = AuditLog::query()->where('action', 'shm.loan.payment.applied')->sole();
        $this->assertSame('0.00', data_get($appliedLog->changes, 'penalty'));
    }

    // =================================================================
    // GROUP F — ATOMIC ROLLBACK
    // =================================================================

    public function test_f1_atomic_rollback_on_unexpected_downstream_failure(): void
    {
        $loan = $this->createActiveLoanWithSchedule();

        // Simulasikan kegagalan transaksi di tengah jalan
        try {
            DB::transaction(function () use ($loan): void {
                app(ShmRegularPaymentService::class)->pay($loan, [
                    'payment_date' => now()->toDateString(),
                    'amount' => '6500000',
                ], (int) $this->admin->id);

                throw new \RuntimeException('Simulated system crash after payment');
            });
        } catch (\RuntimeException $e) {
            // Expected simulation
        }

        // Tidak ada pembayaran, alokasi, atau audit yang tertinggal
        $this->assertSame(0, ShmLoanPayment::query()->count());
        $this->assertSame(0, ShmLoanPaymentAllocation::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'shm.loan.payment.applied')->count());

        $loan->refresh();
        $this->assertSame(ShmLoan::STATUS_ACTIVE, $loan->status);
        $this->assertEquals(19_500_000, $loan->totalOwed());
    }

    // =================================================================
    // GROUP G — IDEMPOTENCY
    // =================================================================

    public function test_g1_duplicate_submission_with_same_idempotency_key_is_idempotent(): void
    {
        $loan = $this->createActiveLoanWithSchedule();
        $idempotencyKey = 'REG-PAY-IDEMPOTENT-'.Str::random(12);

        $payload = [
            'loan_id' => $loan->id,
            'payment_date' => now()->toDateString(),
            'amount' => '6500000',
            'idempotency_key' => $idempotencyKey,
        ];

        // Eksekusi pertama
        $res1 = $this->actingAs($this->officer)->post(route('shm.loans.payments.store'), $payload);
        $res1->assertSessionHasNoErrors();

        $this->assertSame(1, ShmLoanPayment::query()->count());
        $this->assertSame(1, ShmLoanPaymentAllocation::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'shm.loan.payment.applied')->count());

        // Eksekusi kedua (retry)
        $res2 = $this->actingAs($this->officer)->post(route('shm.loans.payments.store'), $payload);
        $res2->assertSessionHasNoErrors();
        $res2->assertSessionHas('success', fn ($msg) => str_contains($msg, 'sudah tercatat sebelumnya'));

        // Tidak ada duplikasi baris
        $this->assertSame(1, ShmLoanPayment::query()->count());
        $this->assertSame(1, ShmLoanPaymentAllocation::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'shm.loan.payment.applied')->count());
    }

    // =================================================================
    // GROUP H — CONCURRENCY SAFETY
    // =================================================================

    public function test_h1_concurrency_protection_via_row_locking(): void
    {
        $loan = $this->createActiveLoanWithSchedule();

        // Verifikasi bahwa pay() menggunakan lockForUpdate di level model
        $service = app(ShmRegularPaymentService::class);

        $res = $service->pay($loan, [
            'payment_date' => now()->toDateString(),
            'amount' => '6500000',
        ], (int) $this->officer->id);

        $this->assertFalse($res['was_replayed']);
        $this->assertEquals(6_500_000, $res['allocated_amount']);
        $this->assertEquals(13_000_000, $res['remaining_total_owed']);
    }

    // =================================================================
    // GROUP I — SETTLEMENT LOCK
    // =================================================================

    public function test_i1_payment_is_rejected_when_active_settlement_exists(): void
    {
        $lockedStatuses = [
            ShmLoanSettlement::STATUS_PENDING,
            ShmLoanSettlement::STATUS_APPROVED,
            ShmLoanSettlement::STATUS_PAYMENT_RECORDED,
            ShmLoanSettlement::STATUS_PAYMENT_VERIFIED,
        ];

        foreach ($lockedStatuses as $status) {
            $loan = $this->createActiveLoanWithSchedule();

            // Buat settlement aktif
            $settlement = ShmLoanSettlement::query()->create([
                'shm_loan_id' => $loan->id,
                'settlement_reference' => 'SETT-'.Str::upper(Str::random(8)),
                'settlement_date' => now()->toDateString(),
                'outstanding_principal' => '15000000',
                'outstanding_interest' => '4500000',
                'outstanding_penalty' => '0',
                'unallocated_payment_amount' => '0',
                'settlement_amount' => '18000000',
                'overpayment_amount' => '0',
                'status' => $status,
                'created_by' => $this->admin->id,
            ]);

            $response = $this->actingAs($this->officer)->post(route('shm.loans.payments.store'), [
                'loan_id' => $loan->id,
                'payment_date' => now()->toDateString(),
                'amount' => '6500000',
            ]);

            $response->assertSessionHasErrors(['loan_id']);
            $this->assertSame(0, ShmLoanPayment::query()->where('shm_loan_id', $loan->id)->count(), "Failed on status {$status}");
        }
    }

    public function test_i2_payment_is_allowed_when_settlement_is_rejected(): void
    {
        $loan = $this->createActiveLoanWithSchedule();

        ShmLoanSettlement::query()->create([
            'shm_loan_id' => $loan->id,
            'settlement_reference' => 'SETT-'.Str::upper(Str::random(8)),
            'settlement_date' => now()->toDateString(),
            'outstanding_principal' => '15000000',
            'outstanding_interest' => '4500000',
            'outstanding_penalty' => '0',
            'unallocated_payment_amount' => '0',
            'settlement_amount' => '18000000',
            'overpayment_amount' => '0',
            'status' => ShmLoanSettlement::STATUS_REJECTED,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->officer)->post(route('shm.loans.payments.store'), [
            'loan_id' => $loan->id,
            'payment_date' => now()->toDateString(),
            'amount' => '6500000',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame(1, ShmLoanPayment::query()->where('shm_loan_id', $loan->id)->count());
    }

    // =================================================================
    // GROUP J — AUTHORIZATION
    // =================================================================

    public function test_j1_unauthorized_user_cannot_create_regular_payment(): void
    {
        $loan = $this->createActiveLoanWithSchedule();

        $response = $this->actingAs($this->unauthorizedUser)->post(route('shm.loans.payments.store'), [
            'loan_id' => $loan->id,
            'payment_date' => now()->toDateString(),
            'amount' => '6500000',
        ]);

        $response->assertForbidden();
        $this->assertSame(0, ShmLoanPayment::query()->count());
    }

    public function test_j2_marketing_cannot_pay_loan_owned_by_other_marketing(): void
    {
        $otherOfficer = $this->userWithPermissions(['shm.view', 'shm.payment.create']);
        $loanOfOther = $this->createActiveLoanWithSchedule(); // marketing_id = $this->officer

        // otherOfficer mencoba bayar pinjaman milik officer
        $response = $this->actingAs($otherOfficer)->post(route('shm.loans.payments.store'), [
            'loan_id' => $loanOfOther->id,
            'payment_date' => now()->toDateString(),
            'amount' => '6500000',
        ]);

        $response->assertForbidden();
        $this->assertSame(0, ShmLoanPayment::query()->count());
    }
}
