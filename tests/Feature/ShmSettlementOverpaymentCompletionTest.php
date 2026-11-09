<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Member;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SavingProduct;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Models\Shm\ShmSavingsAccount;
use App\Models\Shm\ShmSavingsTransaction;
use App\Models\ShmAccountingMapping;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanInstallment;
use App\Models\ShmLoanPayment;
use App\Models\ShmLoanProduct;
use App\Models\ShmLoanSettlement;
use App\Models\ShmProductAccounting;
use App\Models\ShmSettlementCorrection;
use App\Models\User;
use App\Services\Accounting\ShmAccountingMappingService;
use App\Services\Shm\ShmLoanPaymentService;
use App\Services\Shm\ShmSettlementCorrectionService;
use App\Support\AccountingContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\ShmSavingProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ticket: Ubah Overpayment Pelunasan SHM Menjadi Bunga Pendapatan.
 *
 * Verifikasi menyeluruh:
 * - Test 1: Settlement tanpa overpayment (Exact Payment).
 * - Test 2: Settlement dengan overpayment -> Bunga Pendapatan SHM (COA 4-1101) & Debit Kas full payment.
 * - Test 3: Overpayment TIDAK masuk ke Simpanan SHM maupun Simpanan Koperasi.
 * - Test 4: Isolasi koperasi: akun dan transaksi koperasi tidak terdampak.
 * - Test 5: Settlement Correction membalik overpayment revenue via journal reversal tanpa menyentuh Simpanan.
 * - Test 6: Invarian principal, schedule angsuran, dan idempotensi.
 */
class ShmSettlementOverpaymentCompletionTest extends TestCase
{
    use RefreshDatabase;

    /** Kewajiban pelunasan fixture (lihat docblock). */
    private const SETTLEMENT_AMOUNT = 31_000_000;

    /** Pembayaran fixture dengan lebih bayar Rp1.000.000. */
    private const OVERPAYMENT_PAYMENT = 32_000_000;

    private const OVERPAYMENT = 1_000_000;

    private User $user;

    private ShmLoanProduct $shmProduct;

    private ChartOfAccount $shmCash;

    private ChartOfAccount $shmReceivable;

    private ChartOfAccount $shmInterest;

    private ChartOfAccount $shmPenalty;

    private ChartOfAccount $shmDiscount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(ShmSavingProductSeeder::class);

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
                'shm.settlement.verify',
                'shm.settlement.approve',
            ])->pluck('id')
        );
        $this->user->roles()->attach($role->id);

        // COA Akuntansi SHM
        $this->shmCash = ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::SHM, 'code' => '1-1101'],
            [
                'name' => 'Kas Operasional SHM',
                'category' => ChartOfAccount::CATEGORY_ASSET,
                'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
                'is_active' => true,
            ]
        );

        $this->shmReceivable = ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::SHM, 'code' => '1-1301'],
            [
                'name' => 'Piutang Pokok SHM',
                'category' => ChartOfAccount::CATEGORY_ASSET,
                'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
                'is_active' => true,
            ]
        );

        $this->shmInterest = ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::SHM, 'code' => '4-1101'],
            [
                'name' => 'Pendapatan Bunga SHM',
                'category' => ChartOfAccount::CATEGORY_REVENUE,
                'normal_balance' => ChartOfAccount::NORMAL_CREDIT,
                'is_active' => true,
            ]
        );

        $this->shmPenalty = ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::SHM, 'code' => '4-1201'],
            [
                'name' => 'Pendapatan Denda SHM',
                'category' => ChartOfAccount::CATEGORY_REVENUE,
                'normal_balance' => ChartOfAccount::NORMAL_CREDIT,
                'is_active' => true,
            ]
        );

        $this->shmDiscount = ChartOfAccount::firstOrCreate(
            ['accounting_context' => AccountingContext::SHM, 'code' => '4-1901'],
            [
                'name' => 'Diskon Pelunasan SHM',
                'category' => ChartOfAccount::CATEGORY_REVENUE,
                'normal_balance' => ChartOfAccount::NORMAL_DEBIT,
                'is_active' => true,
            ]
        );

        // SHM Loan Product
        $this->shmProduct = ShmLoanProduct::firstOrCreate(
            ['code' => 'PJS-TEST'],
            ['name' => 'Pinjaman Siaga Test', 'category' => ShmLoanProduct::CATEGORY_PERUMAHAN, 'is_active' => true]
        );

        ShmProductAccounting::updateOrCreate(
            [
                'shm_loan_product_id' => $this->shmProduct->id,
                'accounting_context' => AccountingContext::SHM,
            ],
            [
                'cash_account_id' => $this->shmCash->id,
                'receivable_account_id' => $this->shmReceivable->id,
                'interest_revenue_account_id' => $this->shmInterest->id,
                'penalty_revenue_account_id' => $this->shmPenalty->id,
                'settlement_discount_account_id' => $this->shmDiscount->id,
                'is_active' => true,
            ]
        );

        app(ShmAccountingMappingService::class)->saveMapping([
            'accounting_context' => AccountingContext::SHM,
            'business_event' => ShmAccountingMapping::EVENT_SETTLEMENT,
            'debit_account_role' => 'cash',
            'credit_account_role' => 'receivable',
        ], (int) $this->user->id);
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
            'shm_loan_product_id' => $this->shmProduct->id,
            'application_date' => now()->toDateString(),
            'principal' => '30000000',
            'tenor_months' => 2,
            'first_due_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'marketing_id' => $this->user->id,
            'status' => 'approved',
        ]);

        $loan = ShmLoan::factory()->create([
            'shm_customer_id' => $customer->id,
            'shm_loan_product_id' => $this->shmProduct->id,
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

    // ==================================================================
    // TEST 1 — EXACT PAYMENT (WITHOUT OVERPAYMENT)
    // ==================================================================

    public function test_exact_payment_completes_without_overpayment_and_balanced_journal(): void
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

        // Tidak ada mutasi simpanan apa pun
        $this->assertSame(0, ShmSavingsTransaction::query()->count());
        $this->assertSame(0, SavingsTransaction::query()->count());

        // Verifikasi Jurnal Settlement
        $journal = JournalEntry::where('source_type', ShmLoanSettlement::class)
            ->where('source_id', $settlement->id)
            ->first();
        $this->assertNotNull($journal);
        $this->assertSame(AccountingContext::SHM, $journal->accounting_context);
        $this->assertTrue($journal->isBalanced());
        $this->assertEquals((float) self::SETTLEMENT_AMOUNT, (float) $journal->totalDebit());
        $this->assertEquals((float) self::SETTLEMENT_AMOUNT, (float) $journal->totalCredit());

        // Debit Kas = settlement_amount (31jt)
        $this->assertEquals((float) self::SETTLEMENT_AMOUNT, (float) $journal->lines()->where('account_id', $this->shmCash->id)->value('debit'));

        // Credit Piutang Pokok = 30jt, Credit Pendapatan Bunga = 1jt
        $this->assertEquals(30_000_000.0, (float) $journal->lines()->where('account_id', $this->shmReceivable->id)->value('credit'));
        $this->assertEquals(1_000_000.0, (float) $journal->lines()->where('account_id', $this->shmInterest->id)->value('credit'));
    }

    // ==================================================================
    // TEST 2 & 3 — SETTLEMENT WITH OVERPAYMENT -> BUNGA PENDAPATAN
    // ==================================================================

    public function test_overpayment_is_recognized_as_interest_revenue_and_not_in_savings(): void
    {
        $member = $this->createMember();
        $loan = $this->activeLoanFor($this->customerFor($member));
        $settlement = $this->verifiedSettlement($loan, (string) self::OVERPAYMENT_PAYMENT);

        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.complete', $loan))
            ->assertSessionHas('success');

        $settlement->refresh();
        $loan->refresh();

        // 1. overpayment tercatat di model
        $this->assertSame('1000000.00', (string) $settlement->overpayment_amount);
        $this->assertSame(ShmLoanSettlement::STATUS_COMPLETED, $settlement->status);
        $this->assertSame(ShmLoan::STATUS_CLOSED, $loan->status);

        // 2. Overpayment TIDAK masuk ke Simpanan SHM maupun Simpanan Koperasi
        $this->assertSame(0, ShmSavingsTransaction::query()->count());
        $this->assertSame(0, ShmSavingsAccount::query()->count());
        $this->assertSame(0, SavingsTransaction::query()->count());
        $this->assertSame(0, SavingsAccount::query()->count());

        // 3. Verifikasi Jurnal SHM
        $journal = JournalEntry::where('source_type', ShmLoanSettlement::class)
            ->where('source_id', $settlement->id)
            ->first();
        $this->assertNotNull($journal);
        $this->assertSame(AccountingContext::SHM, $journal->accounting_context);
        $this->assertTrue($journal->isBalanced());

        // Debit Kas = Full Payment (32jt)
        $this->assertEquals((float) self::OVERPAYMENT_PAYMENT, (float) $journal->totalDebit());
        $this->assertEquals((float) self::OVERPAYMENT_PAYMENT, (float) $journal->totalCredit());

        $cashLine = $journal->lines()->where('account_id', $this->shmCash->id)->first();
        $this->assertNotNull($cashLine);
        $this->assertEquals(32_000_000.0, (float) $cashLine->debit);

        // Credit Piutang Pokok = 30jt
        $receivableLine = $journal->lines()->where('account_id', $this->shmReceivable->id)->first();
        $this->assertNotNull($receivableLine);
        $this->assertEquals(30_000_000.0, (float) $receivableLine->credit);

        // Credit Pendapatan Bunga Normal = 1jt, Bunga Overpayment = 1jt (Total Kredit Bunga = 2jt)
        $interestLines = $journal->lines()->where('account_id', $this->shmInterest->id)->get();
        $this->assertCount(2, $interestLines);
        $this->assertEquals(2_000_000.0, (float) $interestLines->sum('credit'));

        $overpaymentInterestLine = $interestLines->first(fn ($line) => str_contains($line->description, 'lebih bayar'));
        $this->assertNotNull($overpaymentInterestLine);
        $this->assertEquals(1_000_000.0, (float) $overpaymentInterestLine->credit);

        // Audit Logs
        $this->assertSame(1, AuditLog::query()->where('action', 'shm.loan.settled')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'shm.loan.closed')->count());
    }

    // ==================================================================
    // TEST 4 — COOPERATIVE ISOLATION
    // ==================================================================

    public function test_cooperative_savings_and_accounting_are_strictly_isolated(): void
    {
        $member = $this->createMember();
        $coopProduct = SavingProduct::create([
            'code' => 'SP-COOP-TEST',
            'name' => 'Simpanan Pokok Koperasi',
            'category' => SavingProduct::CATEGORY_PRINCIPAL,
            'is_active' => true,
        ]);
        $coopAccount = SavingsAccount::create([
            'account_number' => 'REK-COOP-001',
            'member_id' => $member->id,
            'saving_product_id' => $coopProduct->id,
            'balance' => 500_000,
            'status' => 'active',
            'opened_at' => now(),
        ]);

        $loan = $this->activeLoanFor($this->customerFor($member));
        $settlement = $this->verifiedSettlement($loan, (string) self::OVERPAYMENT_PAYMENT);

        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.complete', $loan))
            ->assertSessionHas('success');

        // Saldo dan mutasi simpanan koperasi tidak tersentuh sama sekali
        $this->assertEquals(500_000.0, (float) $coopAccount->refresh()->balance);
        $this->assertSame(0, SavingsTransaction::query()->count());

        // Tidak ada jurnal koperasi yang terbuat
        $this->assertSame(0, JournalEntry::where('accounting_context', AccountingContext::KOPERASI)->count());
    }

    // ==================================================================
    // TEST 5 — SETTLEMENT CORRECTION (REVERSAL VIA ACCOUNTING)
    // ==================================================================

    public function test_settlement_correction_reverses_overpayment_revenue_without_savings_reversal(): void
    {
        $member = $this->createMember();
        $loan = $this->activeLoanFor($this->customerFor($member));
        $settlement = $this->verifiedSettlement($loan, (string) self::OVERPAYMENT_PAYMENT);

        $this->actingAs($this->user)
            ->post(route('shm.loans.pelunasan.complete', $loan))
            ->assertSessionHas('success');

        $originalJournal = JournalEntry::where('source_type', ShmLoanSettlement::class)
            ->where('source_id', $settlement->id)
            ->first();
        $this->assertNotNull($originalJournal);
        $this->assertSame(JournalEntry::STATUS_POSTED, $originalJournal->status);

        // Eksekusi Koreksi Pelunasan
        $correctionService = app(ShmSettlementCorrectionService::class);
        $correction = $correctionService->correct(
            $loan,
            $settlement,
            'Koreksi pembatalan pelunasan dengan overpayment',
            (int) $this->user->id,
        );

        $this->assertNotNull($correction);
        $this->assertSame(ShmSettlementCorrection::STATUS_COMPLETED, $correction->status);

        // Jurnal original berstatus REVERSED
        $this->assertSame(JournalEntry::STATUS_REVERSED, $originalJournal->refresh()->status);

        // Reversal compensating journal terbentuk dan seimbang
        $reversalJournal = JournalEntry::where('id', $originalJournal->reversed_by_entry_id)->first();
        $this->assertNotNull($reversalJournal);
        $this->assertSame(JournalEntry::STATUS_POSTED, $reversalJournal->status);
        $this->assertSame(AccountingContext::SHM, $reversalJournal->accounting_context);
        $this->assertTrue($reversalJournal->isBalanced());

        // Debit Pendapatan Bunga 2jt (membalik kredit 2jt dari bunga normal & overpayment)
        $interestDebit = (float) $reversalJournal->lines()->where('account_id', $this->shmInterest->id)->sum('debit');
        $this->assertEquals(2_000_000.0, $interestDebit);

        // Credit Kas 32jt (membalik debit kas 32jt)
        $cashCredit = (float) $reversalJournal->lines()->where('account_id', $this->shmCash->id)->value('credit');
        $this->assertEquals(32_000_000.0, $cashCredit);

        // Simpanan SHM dan Koperasi tetap 0 (tidak pernah tersentuh dan tidak ada reversal simpanan)
        $this->assertSame(0, ShmSavingsTransaction::query()->count());
        $this->assertSame(0, SavingsTransaction::query()->count());
    }

    // ==================================================================
    // TEST 6 — IDEMPOTENCY & PRINCIPAL INVARIANT
    // ==================================================================

    public function test_repeated_completion_is_idempotent_and_preserves_loan_principal(): void
    {
        $member = $this->createMember();
        $loan = $this->activeLoanFor($this->customerFor($member));
        $settlement = $this->verifiedSettlement($loan, (string) self::OVERPAYMENT_PAYMENT);

        foreach ([1, 2, 3] as $attempt) {
            $this->actingAs($this->user)
                ->post(route('shm.loans.pelunasan.complete', $loan))
                ->assertSessionHas('success');

            $this->assertSame(0, ShmSavingsTransaction::query()->count(), "percobaan ke-{$attempt}");
            $this->assertSame(0, SavingsTransaction::query()->count(), "percobaan ke-{$attempt}");
            $this->assertSame(1, AuditLog::query()->where('action', 'shm.loan.settled')->count(), "percobaan ke-{$attempt}");
            $this->assertSame(1, AuditLog::query()->where('action', 'shm.loan.closed')->count(), "percobaan ke-{$attempt}");
            $this->assertSame(1, ShmLoanSettlement::query()->where('status', ShmLoanSettlement::STATUS_COMPLETED)->count());
            $this->assertSame(1, JournalEntry::where('source_type', ShmLoanSettlement::class)->where('source_id', $settlement->id)->count());
        }

        $this->assertSame('30000000.00', (string) $loan->refresh()->principal);
        $this->assertEquals(30_000_000, (float) $loan->installments()->sum('principal_amount'));
    }
}
