<?php

namespace Tests\Feature;

use App\Models\InstallmentPayment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InstallmentPaymentUiContractTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->superadmin = User::factory()->superadmin()->create();
    }

    /**
     * Pinjaman aktif dengan pencairan Rp1.000.000 dan satu jadwal
     * (pokok Rp500.000 + bunga Rp50.000) sehingga `remaining_balance`
     * (pokok tersisa) dan `total_owed` (kewajiban total) bernilai berbeda
     * dan masing-masing dapat diverifikasi secara eksplisit.
     */
    private function createActiveLoanWithObligation(): Loan
    {
        $loan = Loan::factory()->active()->create(['amount' => 1000000]);

        LoanDisbursement::factory()->create([
            'loan_id' => $loan->id,
            'amount' => 1000000,
        ]);

        InstallmentSchedule::factory()->create([
            'loan_id' => $loan->id,
            'installment_number' => 1,
            'principal_amount' => 500000,
            'interest_amount' => 50000,
            'total_amount' => 550000,
            'paid_amount' => 0,
            'remaining_amount' => 550000,
            'status' => 'UPCOMING',
        ]);

        return $loan;
    }

    /** Contract: halaman bayar mengirim remaining_balance & total_owed dari backend. */
    public function test_payment_form_serializes_remaining_balance_and_total_owed(): void
    {
        $loan = $this->createActiveLoanWithObligation();

        $response = $this->actingAs($this->superadmin)
            ->get(route('angsuran.pay.create', $loan->id));

        $response->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Angsuran/Pay')
                ->has('loan.loan_number')
                ->where('loan.remaining_balance', fn ($value): bool => (float) $value === 1000000.0)
                ->where('loan.total_owed', fn ($value): bool => (float) $value === 550000.0)
                ->where('suggested_amount', fn ($value): bool => (float) $value === 550000.0)
            );
    }

    /** Contract: kolom "Sisa Kewajiban" di index menerima remaining_balance terserialisasi. */
    public function test_angsuran_index_serializes_remaining_balance_per_loan(): void
    {
        $loan = $this->createActiveLoanWithObligation();

        $response = $this->actingAs($this->superadmin)
            ->get(route('angsuran.index'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Angsuran/Index')
                ->has('loans.data', 1)
                ->where('loans.data.0.remaining_balance', fn ($value): bool => (float) $value === 1000000.0)
            );
    }

    /** CASE A: pembayaran valid sebesar tagihan (<= total_owed) diproses backend. */
    public function test_valid_payment_within_total_owed_is_accepted(): void
    {
        $loan = $this->createActiveLoanWithObligation();

        $response = $this->actingAs($this->superadmin)
            ->post(route('angsuran.pay.store', $loan->id), [
                'amount' => 550000,
                'payment_date' => now()->format('Y-m-d'),
                'idempotency_key' => (string) Str::uuid(),
            ]);

        $response->assertRedirect(route('angsuran.schedule', $loan->id));

        $payment = InstallmentPayment::query()->where('loan_id', $loan->id)->first();
        $this->assertNotNull($payment);
        $this->assertSame('COMPLETED', $payment->status);
        $this->assertEqualsWithDelta(550000.0, (float) $payment->amount, 0.01);

        // Perilaku existing: kewajiban lunas → pinjaman PAID_OFF.
        $this->assertSame('PAID_OFF', $loan->fresh()->status);
    }

    /** CASE B/D: overpayment tetap ditolak backend — UI bukan authority. */
    public function test_overpayment_above_total_owed_is_still_rejected_by_backend(): void
    {
        $loan = $this->createActiveLoanWithObligation();

        $response = $this->actingAs($this->superadmin)
            ->post(route('angsuran.pay.store', $loan->id), [
                'amount' => 550001,
                'payment_date' => now()->format('Y-m-d'),
            ]);

        $response->assertSessionHasErrors('amount');
        $this->assertStringContainsString(
            'melebihi sisa kewajiban pinjaman',
            session('errors')->first('amount')
        );
        $this->assertSame(0, InstallmentPayment::query()->where('loan_id', $loan->id)->count());
    }

    /** CASE C: nominal nol tetap ditolak validasi backend. */
    public function test_zero_amount_is_still_rejected(): void
    {
        $loan = $this->createActiveLoanWithObligation();

        $response = $this->actingAs($this->superadmin)
            ->post(route('angsuran.pay.store', $loan->id), [
                'amount' => 0,
                'payment_date' => now()->format('Y-m-d'),
            ]);

        $response->assertSessionHasErrors('amount');
        $this->assertSame(0, InstallmentPayment::query()->where('loan_id', $loan->id)->count());
    }
}
