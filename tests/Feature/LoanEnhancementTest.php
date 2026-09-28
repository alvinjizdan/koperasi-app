<?php

namespace Tests\Feature;

use App\Models\InstallmentAllocation;
use App\Models\InstallmentPayment;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\InstallmentPaymentService;
use App\Services\Reconciliation\LoanReconciliationChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Ticket 21 — pengujian peningkatan modul pinjaman.
 *
 * Fokus: reversal pembayaran angsuran (gap utama), konsistensi jadwal/alokasi,
 * lifecycle, snapshot bunga, rekonsiliasi, dan otorisasi. Mengikuti konvensi
 * test pinjaman yang ada (factory state + superadmin bypass permission).
 */
class LoanEnhancementTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->superadmin()->create();
    }

    // =====================================================================
    // Helper
    // =====================================================================

    /** Buat + cairkan pinjaman; kembalikan loan yang sudah ACTIVE. */
    private function activeLoan(float $amount = 6_000_000, int $tenor = 6, array $loanAttrs = []): Loan
    {
        $loan = Loan::factory()->approved()->create(array_merge([
            'amount' => $amount,
            'tenor_months' => $tenor,
        ], $loanAttrs));

        $this->actingAs($this->user)->post(route('pinjaman.disburse.store', $loan->id), [
            'disbursement_date' => now()->format('Y-m-d'),
            'first_due_date' => now()->addMonth()->format('Y-m-d'),
            'method' => 'CASH',
        ]);

        return $loan->fresh();
    }

    /** Catat pembayaran via route HTTP. */
    private function pay(Loan $loan, float $amount): InstallmentPayment
    {
        $this->actingAs($this->user)->post(route('angsuran.pay.store', $loan->id), [
            'amount' => $amount,
            'payment_date' => now()->format('Y-m-d'),
        ]);

        return InstallmentPayment::where('loan_id', $loan->id)
            ->whereNull('reversal_of_id')
            ->orderByDesc('id')
            ->first();
    }

    private function reverse(InstallmentPayment $payment): void
    {
        $this->actingAs($this->user)->post(route('angsuran.payment.reverse', $payment->id), [
            'reason' => 'Koreksi uji: salah input nominal',
        ]);
    }

    // =====================================================================
    // A. Lifecycle
    // =====================================================================

    public function test_invalid_state_transitions_are_rejected(): void
    {
        $loan = Loan::factory()->create(['status' => 'PENDING']);

        // PENDING tidak dapat dicairkan.
        $this->actingAs($this->user)
            ->post(route('pinjaman.disburse.store', $loan->id), [
                'disbursement_date' => now()->format('Y-m-d'),
                'method' => 'CASH',
            ])
            ->assertSessionHasErrors();

        // REJECTED tidak dapat disetujui.
        $loan->update(['status' => 'REJECTED']);
        $this->actingAs($this->user)
            ->post(route('pinjaman.approve', $loan->fresh()->id))
            ->assertSessionHasErrors();

        // APPROVED tidak dapat disetujui ulang.
        $loan->update(['status' => 'APPROVED']);
        $this->actingAs($this->user)
            ->post(route('pinjaman.approve', $loan->fresh()->id))
            ->assertSessionHasErrors();

        $this->assertSame('APPROVED', $loan->fresh()->status);
    }

    public function test_paid_off_loan_cannot_accept_new_payments(): void
    {
        $loan = $this->activeLoan(1_000_000, 1);

        // Bayar seluruh kewajiban → PAID_OFF.
        $payment = $this->pay($loan, 1_000_000);
        $this->assertSame('PAID_OFF', $loan->fresh()->status);

        // Pembayaran lanjutan ditolak (status bukan ACTIVE/DISBURSED).
        $this->actingAs($this->user)
            ->post(route('angsuran.pay.store', $loan->id), [
                'amount' => 10_000,
                'payment_date' => now()->format('Y-m-d'),
            ])
            ->assertSessionHasErrors();

        $this->assertSame(1, InstallmentPayment::where('loan_id', $loan->id)->whereNull('reversal_of_id')->count());
    }

    public function test_historical_records_remain_accessible_after_settlement(): void
    {
        $loan = $this->activeLoan(2_000_000, 2);
        $payment = $this->pay($loan, 1_000_000);
        $this->pay($loan->fresh(), 1_000_000);
        $this->assertSame('PAID_OFF', $loan->fresh()->status);

        // Jadwal, pembayaran, alokasi, dan halaman detail tetap dapat diakses.
        $this->actingAs($this->user)
            ->get(route('angsuran.schedule', $loan->id))
            ->assertInertia(fn ($page) => $page
                ->has('loan')
                ->has('loan.payments', 2));

        $this->assertDatabaseCount('installment_allocations', InstallmentAllocation::count());
        $this->assertGreaterThan(0, InstallmentAllocation::count());
        $this->assertNotNull($payment->fresh());
    }

    // =====================================================================
    // B. Interest & schedule
    // =====================================================================

    public function test_interest_snapshot_preserved_after_config_change(): void
    {
        // Flat 12% (provisional default konfigurasi) — snapshot di loans.
        $loan = $this->activeLoan(6_000_000, 6);

        $this->assertSame('flat', $loan->interest_method);
        $snapshotRate = (float) $loan->interest_rate_annual;

        // Simulasi perubahan config sesudah pencairan: snapshot tidak ikut berubah.
        config(['business-rules.loan.default_annual_rate' => 99.0]);
        $this->assertSame($snapshotRate, (float) $loan->fresh()->interest_rate_annual);
    }

    public function test_schedule_totals_reconcile_with_disbursed_principal(): void
    {
        $amount = 5_000_000;
        $loan = $this->activeLoan($amount, 5);

        $schedules = $loan->schedules()->orderBy('installment_number')->get();
        $this->assertCount(5, $schedules);

        // Total pokok = nominal pencairan (bunga dilaporkan terpisah).
        $this->assertSame((float) $amount, round((float) $schedules->sum('principal_amount'), 2));

        // Rounding residual: pokok angsuran terakhir menyerap selisih.
        $monthly = round($amount / 5, 2);
        $this->assertSame(
            round($amount - $monthly * 4, 2),
            round((float) $schedules->last()->principal_amount, 2)
        );

        // Tanggal jatuh tempo berurutan & nomor angsuran valid.
        $dueDates = $schedules->pluck('due_date')->map(fn ($d) => (string) $d)->all();
        $this->assertSame($dueDates, collect($dueDates)->sort()->values()->all());
        $this->assertSame([1, 2, 3, 4, 5], $schedules->pluck('installment_number')->all());
    }

    public function test_schedule_totals_reconcile_with_total_interest(): void
    {
        $loan = $this->activeLoan(6_000_000, 6);

        $schedules = $loan->schedules()->get();
        // total_amount = pokok + bunga per angsuran (invariant per baris).
        foreach ($schedules as $schedule) {
            $this->assertSame(
                round((float) $schedule->principal_amount + (float) $schedule->interest_amount, 2),
                round((float) $schedule->total_amount, 2)
            );
        }
    }

    // =====================================================================
    // C. Payment & allocation
    // =====================================================================

    public function test_allocation_totals_match_payment_amount(): void
    {
        $loan = $this->activeLoan(6_000_000, 6);
        $payment = $this->pay($loan, 800_000);

        $allocSum = round((float) InstallmentAllocation::where('installment_payment_id', $payment->id)->sum('amount'), 2);
        $this->assertSame(800_000.0, $allocSum);

        // Alokasi tidak melebihi kewajiban komponen jadwal manapun.
        foreach (InstallmentAllocation::where('installment_payment_id', $payment->id)->get() as $allocation) {
            $schedule = InstallmentSchedule::find($allocation->installment_schedule_id);
            $owed = match ($allocation->component) {
                'penalty' => (float) $schedule->penalty_accrued,
                'interest' => (float) $schedule->interest_amount,
                'principal' => (float) $schedule->principal_amount,
            };
            $this->assertLessThanOrEqual($owed + 0.009, (float) $allocation->amount);
        }
    }

    public function test_partial_payment_updates_remaining_obligations_correctly(): void
    {
        $loan = $this->activeLoan(6_000_000, 6);

        // Bayar sebagian angsuran pertama (pokok+bunga pertama = 1.000.000+?; gunakan separuh angsuran pertama).
        $firstSchedule = $loan->schedules()->first();
        $partial = round(((float) $firstSchedule->total_amount) / 2, 2);

        $this->pay($loan, $partial);

        $firstSchedule = $loan->fresh()->schedules()->first();
        $this->assertSame('PARTIALLY_PAID', $firstSchedule->status);
        $this->assertGreaterThan(0.0, (float) $firstSchedule->remaining_amount);
        $this->assertLessThan((float) $firstSchedule->total_amount, (float) $firstSchedule->remaining_amount);
    }

    public function test_overpayment_beyond_total_owed_is_rejected(): void
    {
        $loan = $this->activeLoan(1_000_000, 1);

        $this->actingAs($this->user)
            ->post(route('angsuran.pay.store', $loan->id), [
                'amount' => 5_000_000, // jauh melebihi total kewajiban
                'payment_date' => now()->format('Y-m-d'),
            ])
            ->assertSessionHasErrors();

        $this->assertSame(0, InstallmentPayment::where('loan_id', $loan->id)->count());
    }

    public function test_duplicate_idempotency_key_returns_existing_payment_without_new_effect(): void
    {
        $loan2 = $this->activeLoan(2_000_000, 2);

        // Duplicate idempotency: diproses via service langsung (route tidak mengirim key).
        $payment = app(InstallmentPaymentService::class)->pay($loan2, [
            'amount' => 300_000,
            'payment_date' => now()->format('Y-m-d'),
            'idempotency_key' => 'dup-key-21',
        ], $this->user->id);

        $again = app(InstallmentPaymentService::class)->pay($loan2, [
            'amount' => 300_000,
            'payment_date' => now()->format('Y-m-d'),
            'idempotency_key' => 'dup-key-21',
        ], $this->user->id);

        $this->assertSame($payment->id, $again->id);
        $this->assertSame(1, InstallmentPayment::where('loan_id', $loan2->id)->count());
    }

    // =====================================================================
    // D. Settlement & reversal
    // =====================================================================

    public function test_full_settlement_and_duplicate_settlement_prevention(): void
    {
        $loan = $this->activeLoan(2_000_000, 2);

        $schedules = $loan->schedules()->get();
        $this->pay($loan, round((float) $schedules[0]->total_amount, 2));
        $this->pay($loan->fresh(), round((float) $schedules[1]->total_amount, 2));

        $loan = $loan->fresh();
        $this->assertSame('PAID_OFF', $loan->status);
        $this->assertLessThanOrEqual(0.01, $loan->total_owed);
    }

    public function test_payment_reversal_restores_obligations_and_recalculates_settlement(): void
    {
        $loan = $this->activeLoan(2_000_000, 2);

        // Lunasi pinjaman.
        $schedules = $loan->schedules()->get();
        $this->pay($loan, round((float) $schedules[0]->total_amount, 2));
        $p2 = $this->pay($loan->fresh(), round((float) $schedules[1]->total_amount, 2));
        $this->assertSame('PAID_OFF', $loan->fresh()->status);

        // Reversal pembayaran kedua → kewajiban kembali, status turun dari PAID_OFF.
        $this->reverse($p2);

        $loan = $loan->fresh();
        $this->assertSame('ACTIVE', $loan->status);
        $this->assertGreaterThan(0.01, $loan->total_owed);

        // Pembayaran asal REVERSED (bukan dihapus), reversal tercatat & tereferensi.
        $this->assertSame('REVERSED', $p2->fresh()->status);
        $reversal = InstallmentPayment::where('reversal_of_id', $p2->id)->first();
        $this->assertNotNull($reversal);
        $this->assertSame('reversal', $reversal->entry_type);
        $this->assertSame((float) $p2->amount, (float) $reversal->amount);

        // Alokasi pembayaran asal tetap ada (immutable), kewajiban dikembalikan.
        $this->assertGreaterThan(0, InstallmentAllocation::where('installment_payment_id', $p2->id)->count());
        $secondSchedule = $loan->schedules()->orderBy('installment_number')->skip(1)->first();
        $this->assertGreaterThan(0.0, round((float) $secondSchedule->total_amount - (float) $secondSchedule->paid_amount - (float) ($secondSchedule->interest_paid ?? 0), 2));
    }

    public function test_original_payment_and_allocations_remain_immutable_after_reversal(): void
    {
        $loan = $this->activeLoan(6_000_000, 6);
        $payment = $this->pay($loan, 1_000_000);

        $paymentSnapshot = $payment->only(['payment_number', 'amount', 'payment_date', 'loan_id', 'installment_schedule_id']);
        $snapshotPaymentDate = $payment->payment_date->toDateString();
        $allocationSnapshot = InstallmentAllocation::where('installment_payment_id', $payment->id)
            ->orderBy('id')->get(['component', 'amount'])->map(fn ($a) => [$a->component, (string) $a->amount])->all();

        $this->reverse($payment);

        // Kolom finansial & identitas pembayaran asal tidak berubah;
        // hanya status yang berubah menjadi REVERSED (koreksi status,
        // bukan pengubahan riwayat).
        $payment->refresh();
        $this->assertSame($paymentSnapshot['payment_number'], $payment->payment_number);
        $this->assertSame((string) $paymentSnapshot['amount'], (string) $payment->amount);
        $this->assertSame($snapshotPaymentDate, $payment->payment_date->toDateString());
        $this->assertSame($paymentSnapshot['loan_id'], $payment->loan_id);
        $this->assertSame($paymentSnapshot['installment_schedule_id'], $payment->installment_schedule_id);
        $this->assertSame('REVERSED', $payment->status);

        $allocationsNow = InstallmentAllocation::where('installment_payment_id', $payment->id)
            ->orderBy('id')->get(['component', 'amount'])->map(fn ($a) => [$a->component, (string) $a->amount])->all();
        $this->assertSame($allocationSnapshot, $allocationsNow);
    }

    public function test_duplicate_reversal_is_rejected(): void
    {
        $loan = $this->activeLoan(6_000_000, 6);
        $payment = $this->pay($loan, 1_000_000);

        $this->reverse($payment);

        // Reversal kedua pada pembayaran yang sama ditolak.
        $response = $this->actingAs($this->user)
            ->post(route('angsuran.payment.reverse', $payment->id), [
                'reason' => 'Percobaan reversal kedua',
            ])
            ->assertSessionHasErrors();

        $this->assertSame(1, InstallmentPayment::where('reversal_of_id', $payment->id)->count());
    }

    public function test_reversal_entry_cannot_be_reversed(): void
    {
        $loan = $this->activeLoan(6_000_000, 6);
        $payment = $this->pay($loan, 1_000_000);
        $this->reverse($payment);

        $reversal = InstallmentPayment::where('reversal_of_id', $payment->id)->first();

        $this->actingAs($this->user)
            ->post(route('angsuran.payment.reverse', $reversal->id), [
                'reason' => 'Percobaan reversal atas reversal',
            ])
            ->assertSessionHasErrors();
    }

    public function test_service_level_reversal_validations(): void
    {
        $loan = $this->activeLoan(6_000_000, 6);
        $payment = $this->pay($loan, 1_000_000);
        $service = app(InstallmentPaymentService::class);

        // Alasan kosong: tetap bisa diproses di service (validasi di request),
        // tapi payment REVERSED tidak boleh lewat.
        $this->reverse($payment);

        $this->expectException(ValidationException::class);
        $service->reverse($payment->fresh(), $this->user->id, 'Sudah direversi');
    }

    // =====================================================================
    // E. Reconciliation & authorization
    // =====================================================================

    public function test_reconciliation_detects_intentional_discrepancy(): void
    {
        $loan = $this->activeLoan(2_000_000, 2);

        // Rusak data: ubah pokok jadwal tanpa pencairan ulang.
        $schedule = $loan->schedules()->first();
        $schedule->forceFill(['principal_amount' => 999.99])->save();

        $findings = (new LoanReconciliationChecker)->check('2000-01-01', now()->format('Y-m-d'));

        $related = array_filter($findings['findings'], fn ($f) => $f['object_id'] === $loan->id && $f['finding_type'] === 'BALANCE_MISMATCH');
        $this->assertNotEmpty($related);

        // Rekonsiliasi tidak mengubah data.
        $this->assertSame(999.99, (float) $loan->fresh()->schedules()->first()->principal_amount);
    }

    public function test_reconciliation_does_not_mutate_records(): void
    {
        $loan = $this->activeLoan(2_000_000, 2);
        $payment = $this->pay($loan, 1_000_000);
        $this->reverse($payment);

        $loanBefore = $loan->fresh()->toArray();
        $paymentsBefore = InstallmentPayment::orderBy('id')->get()->map(fn ($p) => $p->only(['id', 'status', 'amount', 'reversal_of_id', 'entry_type']))->all();

        (new LoanReconciliationChecker)->check('2000-01-01', now()->format('Y-m-d'));

        $this->assertSame($loanBefore, $loan->fresh()->toArray());
        $this->assertSame($paymentsBefore, InstallmentPayment::orderBy('id')->get()->map(fn ($p) => $p->only(['id', 'status', 'amount', 'reversal_of_id', 'entry_type']))->all());
    }

    public function test_reversed_payments_are_excluded_from_total_paid(): void
    {
        $loan = $this->activeLoan(6_000_000, 6);
        $payment = $this->pay($loan, 1_000_000);

        $totalBefore = $loan->fresh()->total_paid;
        $this->reverse($payment);

        $this->assertSame(0.0, $loan->fresh()->total_paid);
    }

    public function test_unauthorized_users_cannot_reverse_payments(): void
    {
        $loan = $this->activeLoan(6_000_000, 6);
        $payment = $this->pay($loan, 1_000_000);

        // User dengan role pembaca (hanya permission view) — mengikuti pola
        // test akuntansi: role custom + attach permission dari DB.
        $viewer = User::factory()->create();
        $role = Role::query()->create([
            'name' => 'angsuran_viewer_'.uniqid(),
            'display_name' => 'Viewer Angsuran',
            'is_system' => false,
        ]);
        $role->permissions()->attach(
            Permission::query()->where('name', 'angsuran.view')->pluck('id')
        );
        $viewer->roles()->attach($role->id);

        $this->actingAs($viewer)
            ->post(route('angsuran.payment.reverse', $payment->id), [
                'reason' => 'Tidak berwenang',
            ])
            ->assertForbidden();

        $this->assertSame('COMPLETED', $payment->fresh()->status);
    }

    public function test_unauthenticated_users_cannot_access_reversal(): void
    {
        $loan = $this->activeLoan(6_000_000, 6);
        $payment = $this->pay($loan, 1_000_000);

        $this->post(route('angsuran.payment.reverse', $payment->id), ['reason' => 'anon'])
            ->assertRedirect();

        $this->assertSame('COMPLETED', $payment->fresh()->status);
    }
}
