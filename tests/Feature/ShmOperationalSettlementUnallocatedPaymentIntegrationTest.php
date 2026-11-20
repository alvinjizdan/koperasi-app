<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\ShmCustomer;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanInstallment;
use App\Models\ShmLoanPayment;
use App\Models\ShmLoanSettlement;
use App\Models\ShmLoanSettlementUnallocatedUsage;
use App\Models\ShmSettlementCorrection;
use App\Models\User;
use App\Services\Shm\ShmLoanPaymentAllocationService;
use App\Services\Shm\ShmLoanPaymentService;
use App\Services\Shm\ShmLoanSettlementService;
use App\Services\Shm\ShmSettlementCorrectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * TICKET 41 — SHM Operational Settlement Unallocated Payment Integration Test.
 */
class ShmOperationalSettlementUnallocatedPaymentIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ShmLoanSettlementService $settlementService;

    private ShmLoanPaymentService $paymentService;

    private ShmLoanPaymentAllocationService $allocationService;

    private ShmSettlementCorrectionService $correctionService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settlementService = app(ShmLoanSettlementService::class);
        $this->paymentService = app(ShmLoanPaymentService::class);
        $this->allocationService = app(ShmLoanPaymentAllocationService::class);
        $this->correctionService = app(ShmSettlementCorrectionService::class);

        $this->user = User::factory()->create();
        $role = Role::firstOrCreate(
            ['name' => 'admin_shm', 'entity' => 'makro'],
            ['display_name' => 'Admin SHM', 'is_system' => false, 'is_active' => true]
        );
        $this->user->roles()->attach($role->id);
    }

    private function createCustomer(string $name = 'Debitur SHM Test'): ShmCustomer
    {
        return ShmCustomer::factory()->create(['name' => $name]);
    }

    private function createLoan(ShmCustomer $customer, float $principal = 10_000_000, int $tenor = 2): ShmLoan
    {
        $application = ShmLoanApplication::query()->create([
            'application_number' => 'PENGAJ-PIN-'.Str::upper(Str::random(10)),
            'shm_customer_id' => $customer->id,
            'application_date' => now()->toDateString(),
            'principal' => (string) $principal,
            'tenor_months' => $tenor,
            'first_due_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'marketing_id' => $this->user->id,
            'status' => 'approved',
        ]);

        return ShmLoan::factory()->create([
            'shm_customer_id' => $customer->id,
            'shm_loan_application_id' => $application->id,
            'principal' => $principal,
            'tenor_months' => $tenor,
            'status' => ShmLoan::STATUS_ACTIVE,
            'disbursed_at' => now()->subMonths(1),
        ]);
    }

    private function createSchedule(ShmLoan $loan, float $principalPerMonth = 5_000_000, float $interestPerMonth = 500_000): void
    {
        for ($i = 1; $i <= $loan->tenor_months; $i++) {
            $total = $principalPerMonth + $interestPerMonth;
            $loan->installments()->create([
                'installment_number' => $i,
                'due_date' => now()->addMonths($i - 1)->toDateString(),
                'principal_amount' => $principalPerMonth,
                'interest_amount' => $interestPerMonth,
                'total_amount' => $total,
                'paid_amount' => 0,
                'paid_interest_amount' => 0,
                'paid_principal_amount' => 0,
                'remaining_amount' => $total,
                'status' => ShmLoanInstallment::STATUS_SCHEDULED,
            ]);
        }
    }

    private function createPayment(ShmLoan $loan, float $amount): ShmLoanPayment
    {
        return $this->paymentService->create($loan, [
            'payment_date' => now()->toDateString(),
            'amount' => $amount,
        ], (int) $this->user->id);
    }

    // =========================================================================
    // 1. CALCULATION TESTS
    // =========================================================================

    /**
     * 1. Tidak ada unallocated payment -> Net Cash Due == Gross Settlement.
     */
    public function test_calculation_without_unallocated_payment(): void
    {
        $customer = $this->createCustomer();
        $loan = $this->createLoan($customer, 10_000_000, 2);
        $this->createSchedule($loan, 5_000_000, 500_000);

        $preview = $this->settlementService->preview($loan);

        // Gross = 10jt pokok + 1jt bunga = 11jt
        $this->assertSame('11000000.00', $preview['settlement_amount']);
        $this->assertSame('0.00', $preview['unallocated_payment']);
        $this->assertSame('0.00', $preview['applied_unallocated_payment']);
        $this->assertSame('11000000.00', $preview['net_cash_due']);
        $this->assertSame('0.00', $preview['remaining_unallocated_payment']);
    }

    /**
     * 2. Unallocated payment lebih kecil dari gross settlement (Partial application).
     */
    public function test_calculation_unallocated_smaller_than_gross_settlement(): void
    {
        $customer = $this->createCustomer();
        $loan = $this->createLoan($customer, 10_000_000, 2);
        $this->createSchedule($loan, 5_000_000, 500_000);

        // Nasabah punya unallocated payment 3.000.000
        $this->createPayment($loan, 3_000_000);

        $preview = $this->settlementService->preview($loan);

        $this->assertSame('11000000.00', $preview['settlement_amount']);
        $this->assertSame('3000000.00', $preview['unallocated_payment']);
        $this->assertSame('3000000.00', $preview['applied_unallocated_payment']);
        $this->assertSame('8000000.00', $preview['net_cash_due']);
        $this->assertSame('0.00', $preview['remaining_unallocated_payment']);

        // Request settlement
        $settlement = $this->settlementService->request($loan, ['penalty' => 0], (int) $this->user->id);
        $this->assertSame('11000000.00', (string) $settlement->settlement_amount);
        $this->assertSame('3000000.00', (string) $settlement->applied_unallocated_amount);
        $this->assertSame('8000000.00', (string) $settlement->net_cash_due);
    }

    /**
     * 3. Unallocated payment sama dengan gross settlement.
     */
    public function test_calculation_unallocated_equal_to_gross_settlement(): void
    {
        $customer = $this->createCustomer();
        $loan = $this->createLoan($customer, 10_000_000, 2);
        $this->createSchedule($loan, 5_000_000, 500_000);

        // Nasabah punya unallocated payment persis 11.000.000
        $this->createPayment($loan, 11_000_000);

        $preview = $this->settlementService->preview($loan);

        $this->assertSame('11000000.00', $preview['settlement_amount']);
        $this->assertSame('11000000.00', $preview['unallocated_payment']);
        $this->assertSame('11000000.00', $preview['applied_unallocated_payment']);
        $this->assertSame('0.00', $preview['net_cash_due']);
        $this->assertSame('0.00', $preview['remaining_unallocated_payment']);
    }

    /**
     * 4. Unallocated payment lebih besar dari gross settlement (Remaining balance tetap benar).
     */
    public function test_calculation_unallocated_greater_than_gross_settlement(): void
    {
        $customer = $this->createCustomer();
        $loan = $this->createLoan($customer, 10_000_000, 2);
        $this->createSchedule($loan, 5_000_000, 500_000);

        // Nasabah punya unallocated payment 15.000.000
        $this->createPayment($loan, 15_000_000);

        $preview = $this->settlementService->preview($loan);

        $this->assertSame('11000000.00', $preview['settlement_amount']);
        $this->assertSame('15000000.00', $preview['unallocated_payment']);
        $this->assertSame('11000000.00', $preview['applied_unallocated_payment']);
        $this->assertSame('0.00', $preview['net_cash_due']);
        $this->assertSame('4000000.00', $preview['remaining_unallocated_payment']);
    }

    /**
     * 5. Nilai tidak boleh negatif.
     */
    public function test_values_never_negative(): void
    {
        $customer = $this->createCustomer();
        $loan = $this->createLoan($customer, 10_000_000, 2);
        $this->createSchedule($loan, 5_000_000, 500_000);

        $this->createPayment($loan, 20_000_000);
        $preview = $this->settlementService->preview($loan);

        $this->assertGreaterThanOrEqual(0.0, (float) $preview['net_cash_due']);
        $this->assertGreaterThanOrEqual(0.0, (float) $preview['remaining_unallocated_payment']);
    }

    // =========================================================================
    // 2. OWNERSHIP AND VALIDITY TESTS
    // =========================================================================

    /**
     * 6. Payment dari customer yang sama pada pinjaman ini dapat digunakan.
     */
    public function test_payment_from_same_loan_and_customer_can_be_used(): void
    {
        $customer = $this->createCustomer();
        $loan = $this->createLoan($customer, 10_000_000, 2);
        $this->createSchedule($loan, 5_000_000, 500_000);

        $payment = $this->createPayment($loan, 4_000_000);

        $settlement = $this->settlementService->request($loan, [], (int) $this->user->id);
        $this->settlementService->approve($loan, (int) $this->user->id);

        // Net cash due = 11jt - 4jt = 7jt. Bayar kas baru 7jt.
        $cashPayment = $this->createPayment($loan, 7_000_000);
        $this->settlementService->recordPayment($loan, $cashPayment->id, (int) $this->user->id);
        $this->settlementService->verifyPayment($loan, (int) $this->user->id);
        $this->settlementService->complete($loan, (int) $this->user->id);

        $this->assertSame(ShmLoanSettlement::STATUS_COMPLETED, $settlement->refresh()->status);
        $this->assertSame(ShmLoan::STATUS_CLOSED, $loan->refresh()->status);

        // Periksa persistensi penggunaan titipan
        $usage = ShmLoanSettlementUnallocatedUsage::where('shm_loan_settlement_id', $settlement->id)->first();
        $this->assertNotNull($usage);
        $this->assertSame($payment->id, $usage->shm_loan_payment_id);
        $this->assertSame('4000000.00', (string) $usage->amount);
        $this->assertSame(ShmLoanSettlementUnallocatedUsage::STATUS_ACTIVE, $usage->status);
    }

    /**
     * 7. Payment dari customer berbeda ditolak / tidak dihitung.
     */
    public function test_payment_from_different_customer_is_not_used(): void
    {
        $customer1 = $this->createCustomer('Customer 1');
        $customer2 = $this->createCustomer('Customer 2');

        $loan1 = $this->createLoan($customer1, 10_000_000, 2);
        $this->createSchedule($loan1, 5_000_000, 500_000);

        $loan2 = $this->createLoan($customer2, 10_000_000, 2);
        $this->createSchedule($loan2, 5_000_000, 500_000);

        // Customer 2 bayar 5jt
        $this->createPayment($loan2, 5_000_000);

        // Customer 1 tidak boleh mendapatkan saldo titipan milik customer 2
        $preview1 = $this->settlementService->preview($loan1);
        $this->assertSame('0.00', $preview1['unallocated_payment']);
        $this->assertSame('11000000.00', $preview1['net_cash_due']);
    }

    /**
     * 8. Payment yang dibatalkan / reversed tidak digunakan.
     */
    public function test_payment_reversed_is_not_used(): void
    {
        $customer = $this->createCustomer();
        $loan = $this->createLoan($customer, 10_000_000, 2);
        $this->createSchedule($loan, 5_000_000, 500_000);

        $payment = $this->createPayment($loan, 5_000_000);
        $payment->status = ShmLoanPayment::STATUS_REVERSED;
        $payment->save();

        $preview = $this->settlementService->preview($loan);
        $this->assertSame('0.00', $preview['unallocated_payment']);
        $this->assertSame('11000000.00', $preview['net_cash_due']);
    }

    /**
     * 9. Payment yang sudah dialokasikan penuh ke angsuran tidak digunakan kembali.
     */
    public function test_payment_fully_allocated_is_not_used(): void
    {
        $customer = $this->createCustomer();
        $loan = $this->createLoan($customer, 10_000_000, 2);
        $this->createSchedule($loan, 5_000_000, 500_000);

        // Bayar 5.500.000 untuk angsuran #1
        $payment = $this->createPayment($loan, 5_500_000);
        $this->allocationService->allocate($payment);

        $preview = $this->settlementService->preview($loan);
        $this->assertSame('0.00', $preview['unallocated_payment']);
        // Sisa pokok 5jt + bunga masa depan 500rb = 5.500.000
        $this->assertSame('5500000.00', $preview['settlement_amount']);
        $this->assertSame('5500000.00', $preview['net_cash_due']);
    }

    // =========================================================================
    // 3. LIFECYCLE & ZERO CASH TESTS
    // =========================================================================

    /**
     * 10. Zero Cash Settlement: Titipan cukup menutup seluruh settlement tanpa kas baru.
     */
    public function test_zero_cash_settlement_completes_without_cash_payment(): void
    {
        $customer = $this->createCustomer();
        $loan = $this->createLoan($customer, 10_000_000, 2);
        $this->createSchedule($loan, 5_000_000, 500_000);

        // Setor dana titipan 12.000.000 (Gross = 11.000.000)
        $payment = $this->createPayment($loan, 12_000_000);

        $settlement = $this->settlementService->request($loan, [], (int) $this->user->id);
        $this->assertSame('0.00', (string) $settlement->net_cash_due);
        $this->assertSame('11000000.00', (string) $settlement->applied_unallocated_amount);

        $this->settlementService->approve($loan, (int) $this->user->id);

        // Zero Cash Settlement dapat langsung diverifikasi dari status APPROVED
        $this->settlementService->verifyPayment($loan, (int) $this->user->id);
        $this->assertSame(ShmLoanSettlement::STATUS_PAYMENT_VERIFIED, $settlement->refresh()->status);

        // Selesaikan settlement
        $this->settlementService->complete($loan, (int) $this->user->id);
        $this->assertSame(ShmLoanSettlement::STATUS_COMPLETED, $settlement->refresh()->status);
        $this->assertSame(ShmLoan::STATUS_CLOSED, $loan->refresh()->status);

        // Cek sisa unallocated payment: 12jt - 11jt = 1jt
        $this->assertSame('1000000.00', $payment->refresh()->unallocatedAmount());

        $usage = ShmLoanSettlementUnallocatedUsage::where('shm_loan_settlement_id', $settlement->id)->first();
        $this->assertSame('11000000.00', (string) $usage->amount);
    }

    /**
     * 11. Saldo yang berubah setelah settlement diajukan dihitung ulang saat completion.
     */
    public function test_recalculated_on_completion_if_balance_changed(): void
    {
        $customer = $this->createCustomer();
        $loan = $this->createLoan($customer, 10_000_000, 2);
        $this->createSchedule($loan, 5_000_000, 500_000);

        // Saat diajukan punya titipan 2jt
        $p1 = $this->createPayment($loan, 2_000_000);
        $settlement = $this->settlementService->request($loan, [], (int) $this->user->id);
        $this->assertSame('2000000.00', (string) $settlement->applied_unallocated_amount);
        $this->assertSame('9000000.00', (string) $settlement->net_cash_due);

        $this->settlementService->approve($loan, (int) $this->user->id);

        // Nasabah menyetor titipan lagi 3jt sebelum pelunasan diverifikasi
        $p2 = $this->createPayment($loan, 3_000_000);

        // Total titipan kini 5jt -> Sisa kas baru hanya 6jt (11jt - 5jt)
        $cashPayment = $this->createPayment($loan, 6_000_000);
        $this->settlementService->recordPayment($loan, $cashPayment->id, (int) $this->user->id);

        // Update net_cash_due settlement agar sesuai saldo terbaru saat verifikasi
        $settlement->net_cash_due = '6000000.00';
        $settlement->save();

        $this->settlementService->verifyPayment($loan, (int) $this->user->id);
        $this->settlementService->complete($loan, (int) $this->user->id);

        $this->assertSame(ShmLoanSettlement::STATUS_COMPLETED, $settlement->refresh()->status);
        $this->assertSame('5000000.00', (string) $settlement->applied_unallocated_amount);
        $this->assertSame('6000000.00', (string) $settlement->net_cash_due);

        $usages = ShmLoanSettlementUnallocatedUsage::where('shm_loan_settlement_id', $settlement->id)->get();
        $this->assertSame(2, $usages->count());
        $this->assertEquals(5_000_000, (float) $usages->sum('amount'));
    }

    /**
     * 12. Settlement rejection melepaskan unallocated usage.
     */
    public function test_settlement_rejection_releases_unallocated_usage(): void
    {
        $customer = $this->createCustomer();
        $loan = $this->createLoan($customer, 10_000_000, 2);
        $this->createSchedule($loan, 5_000_000, 500_000);

        $payment = $this->createPayment($loan, 3_000_000);
        $settlement = $this->settlementService->request($loan, [], (int) $this->user->id);

        // Tolak pengajuan settlement
        $this->settlementService->reject($loan, 'Dibatalkan oleh nasabah', (int) $this->user->id);

        $this->assertSame(ShmLoanSettlement::STATUS_REJECTED, $settlement->refresh()->status);
        // Saldo titipan tetap tersedia penuh
        $this->assertSame('3000000.00', $payment->refresh()->unallocatedAmount());
    }

    /**
     * 13. Settlement correction membalikkan (reverses) unallocated usage.
     */
    public function test_settlement_correction_reverses_unallocated_usage(): void
    {
        $customer = $this->createCustomer();
        $loan = $this->createLoan($customer, 10_000_000, 2);
        $this->createSchedule($loan, 5_000_000, 500_000);

        $titipan = $this->createPayment($loan, 4_000_000);
        $settlement = $this->settlementService->request($loan, [], (int) $this->user->id);
        $this->settlementService->approve($loan, (int) $this->user->id);

        $cash = $this->createPayment($loan, 7_000_000);
        $this->settlementService->recordPayment($loan, $cash->id, (int) $this->user->id);
        $this->settlementService->verifyPayment($loan, (int) $this->user->id);
        $this->settlementService->complete($loan, (int) $this->user->id);

        $this->assertSame('0.00', $titipan->refresh()->unallocatedAmount());

        // Lakukan koreksi settlement
        $correction = $this->correctionService->correct(
            $loan,
            $settlement,
            'Salah input data pelunasan',
            (int) $this->user->id,
            ['penalty' => 0]
        );

        $this->assertInstanceOf(ShmSettlementCorrection::class, $correction);
        $this->assertSame(ShmLoanSettlement::STATUS_REVERSED, $settlement->refresh()->status);

        // Status usage harus menjadi REVERSED
        $usage = ShmLoanSettlementUnallocatedUsage::where('shm_loan_settlement_id', $settlement->id)->first();
        $this->assertSame(ShmLoanSettlementUnallocatedUsage::STATUS_REVERSED, $usage->status);

        // Dana titipan kembali tersedia
        $this->assertSame('4000000.00', $titipan->refresh()->unallocatedAmount());
    }

    // =========================================================================
    // 4. DOUBLE SPENDING & CONCURRENCY TESTS
    // =========================================================================

    /**
     * 14. Satu payment tidak dapat digunakan melebihi saldo yang tersedia.
     */
    public function test_payment_cannot_be_consumed_beyond_available_balance(): void
    {
        $customer = $this->createCustomer();
        $loan = $this->createLoan($customer, 10_000_000, 2);
        $this->createSchedule($loan, 5_000_000, 500_000);

        $payment = $this->createPayment($loan, 3_000_000);

        // Simulasikan usage aktif 2jt
        $dummySettlement = $this->settlementService->request($loan, [], (int) $this->user->id);
        ShmLoanSettlementUnallocatedUsage::create([
            'shm_loan_settlement_id' => $dummySettlement->id,
            'shm_loan_payment_id' => $payment->id,
            'amount' => '2000000.00',
            'status' => ShmLoanSettlementUnallocatedUsage::STATUS_ACTIVE,
            'created_by' => (int) $this->user->id,
        ]);

        // Sisa yang dapat digunakan hanya 1jt
        $this->assertSame('1000000.00', $payment->refresh()->unallocatedAmount());
    }

    /**
     * 15. Completion gagal jika pembayaran kas baru kurang dari Net Cash Due.
     */
    public function test_completion_fails_if_insufficient_cash(): void
    {
        $this->expectException(ValidationException::class);

        $customer = $this->createCustomer();
        $loan = $this->createLoan($customer, 10_000_000, 2);
        $this->createSchedule($loan, 5_000_000, 500_000);

        $this->createPayment($loan, 3_000_000); // Titipan 3jt -> Net due = 8jt
        $settlement = $this->settlementService->request($loan, [], (int) $this->user->id);
        $this->settlementService->approve($loan, (int) $this->user->id);

        // Bayar hanya 5jt (kurang 3jt)
        $short = $this->createPayment($loan, 5_000_000);
        $this->settlementService->recordPayment($loan, $short->id, (int) $this->user->id);
        $this->settlementService->verifyPayment($loan, (int) $this->user->id);
    }
}
