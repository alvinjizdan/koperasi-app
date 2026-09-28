<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\CashTransaction;
use App\Models\InstallmentPayment;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\Member;
use App\Models\SavingProduct;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Services\CashierService;
use App\Services\Reconciliation\CashReconciliationChecker;
use App\Services\SavingsTransactionService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ticket 22 — integritas transaksi kasir & transaksi keuangan.
 *
 * Melengkapi gap pengujian yang terverifikasi (bukan duplikasi test yang ada):
 * - dampak kas dari modul sumber ketika sesi asal SUDAH DITUTUP;
 * - snapshot total sesi terhadap mutasi (totals() sebagai sumber tunggal);
 * - reversal angsuran/simpanan menghasilkan dampak kas yang konsisten;
 * - rekonsiliasi kas: transaksi ganda, reversal sah tanpa false positive,
 *   sifat read-only, dan pembatasan sumber data kas vs jurnal akuntansi.
 */
class CashierIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->superadmin()->create();
    }

    private function cashier(): CashierService
    {
        return app(CashierService::class);
    }

    private function openSession(float $openingBalance = 1_000_000): CashierSession
    {
        $this->actingAs($this->user)->post(route('kasir.sesi.store'), [
            'operational_date' => now()->format('Y-m-d'),
            'opening_balance' => $openingBalance,
        ]);

        return CashierSession::where('opened_by', $this->user->id)->orderByDesc('id')->firstOrFail();
    }

    private function closeSession(CashierSession $session): CashierSession
    {
        $balance = $this->cashier()->totals($session->fresh())['closing_balance'];

        $this->actingAs($this->user)->post(route('kasir.sesi.close.store', $session->id), [
            'physical_closing_balance' => $balance,
            'closing_notes' => 'Tutup uji',
        ]);

        return $session->fresh();
    }

    // =====================================================================
    // A. Sesi kasir — snapshot & sumber saldo tunggal
    // =====================================================================

    public function test_session_totals_snapshot_matches_mutation_ledger(): void
    {
        $session = $this->openSession(500_000);

        $this->actingAs($this->user)->post(route('kasir.penerimaan.store'), [
            'amount' => 200_000,
            'category' => 'Pendapatan Lain-lain',
            'transaction_date' => now()->format('Y-m-d'),
        ]);
        $this->actingAs($this->user)->post(route('kasir.pengeluaran.store'), [
            'amount' => 50_000,
            'category' => 'Biaya Operasional',
            'transaction_date' => now()->format('Y-m-d'),
        ]);

        $totals = $this->cashier()->totals($session->fresh());
        $this->assertSame(500_000.0, $totals['opening_balance']);
        $this->assertSame(200_000.0, $totals['income']);
        $this->assertSame(50_000.0, $totals['expense']);
        $this->assertSame(650_000.0, $totals['closing_balance']);

        // Snapshot penutupan dihitung dari totals() yang sama — tidak ada sumber kedua.
        $closed = $this->closeSession($session->fresh());
        $this->assertSame(650_000.0, round((float) $closed->system_closing_balance, 2));
        $this->assertSame(200_000.0, round((float) $closed->total_income, 2));
        $this->assertSame(50_000.0, round((float) $closed->total_expense, 2));
        $this->assertSame(0.0, round((float) $closed->difference, 2));
    }

    public function test_manual_cash_transaction_without_open_session_is_redirect_not_silent(): void
    {
        $session = $this->openSession(1_000_000);
        $this->closeSession($session);

        // Operator tanpa sesi terbuka: POST transaksi kas manual diarahkan ke
        // halaman buka sesi (tidak ada penyimpanan senyap).
        $this->actingAs($this->user)
            ->post(route('kasir.penerimaan.store'), [
                'amount' => 10_000,
                'category' => 'Pendapatan Lain-lain',
                'transaction_date' => now()->format('Y-m-d'),
            ])
            ->assertRedirect();

        // Tidak ada transaksi kas yang tersimpan senyap pada sesi tertutup.
        $this->assertSame(0, CashTransaction::where('cashier_session_id', $session->id)->count());
    }

    // =====================================================================
    // B. Dampak kas modul sumber pada sesi tertutup
    // =====================================================================

    public function test_source_cash_flow_after_origin_session_closed_follows_available_policy(): void
    {
        // Kebijakan tersedia (KSR-05, diverifikasi): transaksi modul sumber
        // memakai sesi TERBUKA operator saat ini; setelah sesi ditutup (tidak
        // ada sesi), transaksi sumber tetap sah namun dampak kas DILEWATI dan
        // teraudit — tidak ada mutasi kas parsial, tidak ada entri kas yatim.
        $session = $this->openSession(1_000_000);
        $this->closeSession($session);

        $product = SavingProduct::factory()->create(['is_active' => true]);
        $member = Member::factory()->create(['status' => 'active']);
        $account = SavingsAccount::factory()->create([
            'member_id' => $member->id,
            'saving_product_id' => $product->id,
            'status' => 'active',
            'balance' => 0,
        ]);

        $transaction = app(SavingsTransactionService::class)->deposit($account->fresh(), [
            'amount' => 100_000,
            'transaction_date' => now()->format('Y-m-d'),
            'processed_by' => $this->user->id,
        ]);

        // Transaksi simpanan sah, saldo terupdate...
        $this->assertSame(100_000.0, (float) $account->fresh()->balance);
        // ...namun TIDAK ada dampak kas pada sesi yang sudah ditutup.
        $this->assertSame(0, CashTransaction::where('cashier_session_id', $session->id)->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'cashier.source.skipped',
            'object_type' => SavingsTransaction::class,
            'object_id' => $transaction->id,
        ]);
    }

    public function test_source_cash_impact_skipped_without_open_session_leaves_no_orphan_cash_entry(): void
    {
        // Tanpa sesi terbuka: transaksi sumber tetap sah, tanpa entri kas,
        // teraudit (cashier.source.skipped) — tidak ada mutasi parsial.
        $product = SavingProduct::factory()->create(['is_active' => true]);
        $member = Member::factory()->create(['status' => 'active']);
        $account = SavingsAccount::factory()->create([
            'member_id' => $member->id,
            'saving_product_id' => $product->id,
            'status' => 'active',
            'balance' => 0,
        ]);

        $transaction = app(SavingsTransactionService::class)->deposit($account->fresh(), [
            'amount' => 75_000,
            'transaction_date' => now()->format('Y-m-d'),
            'processed_by' => $this->user->id,
        ]);

        $this->assertNotNull($transaction->fresh());
        $this->assertSame(75_000.0, (float) $account->fresh()->balance);
        $this->assertSame(0, CashTransaction::count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'cashier.source.skipped',
            'object_type' => SavingsTransaction::class,
            'object_id' => $transaction->id,
        ]);
    }

    // =====================================================================
    // C. Reversal — konsistensi dampak kas
    // =====================================================================

    public function test_installment_reversal_creates_compensating_cash_expense_once(): void
    {
        // Saldo awal cukup untuk pencairan tunai 2 juta + arus balik.
        $session = $this->openSession(5_000_000);

        $loan = Loan::factory()->approved()->create(['amount' => 2_000_000, 'tenor_months' => 2]);
        $this->actingAs($this->user)->post(route('pinjaman.disburse.store', $loan->id), [
            'disbursement_date' => now()->format('Y-m-d'),
            'first_due_date' => now()->addMonth()->format('Y-m-d'),
            'method' => 'CASH',
        ]);

        $this->actingAs($this->user)->post(route('angsuran.pay.store', $loan->id), [
            'amount' => 1_000_000,
            'payment_date' => now()->format('Y-m-d'),
        ]);

        $payment = InstallmentPayment::where('loan_id', $loan->id)->whereNull('reversal_of_id')->first();
        $this->assertNotNull($payment);
        $incomeCount = CashTransaction::where('source_type', InstallmentPayment::class)->where('status', 'POSTED')->count();
        $this->assertSame(1, $incomeCount);

        // Reversal pembayaran → entri kas kompensasi (pengeluaran) pada sesi saat ini.
        $this->actingAs($this->user)->post(route('angsuran.payment.reverse', $payment->id), [
            'reason' => 'Koreksi uji integritas kas',
        ]);

        $reversalPayment = InstallmentPayment::where('reversal_of_id', $payment->id)->first();
        $compensating = CashTransaction::where('source_type', InstallmentPayment::class)
            ->where('source_id', $reversalPayment->id)
            ->first();

        $this->assertNotNull($compensating);
        $this->assertSame('EXPENSE', $compensating->type);
        $this->assertSame(1_000_000.0, (float) $compensating->amount);
        $this->assertSame($session->id, $compensating->cashier_session_id);

        // Net dampak kas angsuran = 0 (5jt - 2jt + 1jt - 1jt = 3jt).
        $this->assertSame(3_000_000.0, $this->cashier()->totals($session->fresh())['closing_balance']);

        // Duplikasi reversal kas: reversal kedua ditolak → tidak ada entri kas tambahan.
        $this->actingAs($this->user)
            ->post(route('angsuran.payment.reverse', $payment->id), ['reason' => 'Percobaan kedua'])
            ->assertSessionHasErrors();
        $this->assertSame(2, CashTransaction::where('source_type', InstallmentPayment::class)->count());
    }

    public function test_savings_reversal_cash_impact_is_compensating_and_single(): void
    {
        $session = $this->openSession(1_000_000);

        $product = SavingProduct::factory()->create(['is_active' => true]);
        $member = Member::factory()->create(['status' => 'active']);
        $account = SavingsAccount::factory()->create([
            'member_id' => $member->id,
            'saving_product_id' => $product->id,
            'status' => 'active',
            'balance' => 0,
        ]);

        $service = app(SavingsTransactionService::class);
        $deposit = $service->deposit($account->fresh(), [
            'amount' => 200_000,
            'transaction_date' => now()->format('Y-m-d'),
            'processed_by' => $this->user->id,
        ]);

        $reversal = $service->reverse($deposit->fresh(), $this->user->id, 'Salah input uji');

        $compensating = CashTransaction::where('source_type', SavingsTransaction::class)
            ->where('source_id', $reversal->id)
            ->first();
        $this->assertNotNull($compensating);
        $this->assertSame('EXPENSE', $compensating->type);
        $this->assertSame(200_000.0, (float) $compensating->amount);
        $this->assertSame(1_000_000.0, $this->cashier()->totals($session->fresh())['closing_balance']);
    }

    public function test_reversal_into_current_session_documented_behavior(): void
    {
        // Kebijakan pending: reversal masuk ke sesi operator SAAT INI (konvensi
        // modul sumber), bukan sesi asal. Uji: sesi asal ditutup, operator membuka
        // sesi baru → entri kompensasi tetap dapat dicatat di sesi baru.
        $originSession = $this->openSession(1_000_000);

        $product = SavingProduct::factory()->create(['is_active' => true]);
        $member = Member::factory()->create(['status' => 'active']);
        $account = SavingsAccount::factory()->create([
            'member_id' => $member->id,
            'saving_product_id' => $product->id,
            'status' => 'active',
            'balance' => 0,
        ]);

        $service = app(SavingsTransactionService::class);
        $deposit = $service->deposit($account->fresh(), [
            'amount' => 300_000,
            'transaction_date' => now()->format('Y-m-d'),
            'processed_by' => $this->user->id,
        ]);

        $this->closeSession($originSession);

        // Sesi baru hari yang sama untuk operator yang sama.
        $newSession = $this->openSession(700_000);

        $reversal = $service->reverse($deposit->fresh(), $this->user->id, 'Koreksi lintas sesi');

        $compensating = CashTransaction::where('source_type', SavingsTransaction::class)
            ->where('source_id', $reversal->id)
            ->first();

        $this->assertNotNull($compensating);
        $this->assertSame($newSession->id, $compensating->cashier_session_id);
        $this->assertNotSame($originSession->id, $compensating->cashier_session_id);
        $this->assertSame(400_000.0, $this->cashier()->totals($newSession->fresh())['closing_balance']);
    }

    // =====================================================================
    // D. Rekonsiliasi kas
    // =====================================================================

    public function test_source_unique_constraint_prevents_duplicate_cash_impact(): void
    {
        // Proteksi DB-level: partial unique index pada (source_type, source_id)
        // mencegah dua dampak kas untuk satu transaksi sumber — dasar idempotensi
        // lintas modul yang aman terhadap request paralel.
        $session = $this->openSession(1_000_000);

        $loan = Loan::factory()->approved()->create(['amount' => 1_000_000, 'tenor_months' => 1]);
        $disbursement = LoanDisbursement::factory()->create([
            'loan_id' => $loan->id,
            'amount' => 1_000_000,
            'disbursement_date' => now()->format('Y-m-d'),
        ]);

        CashTransaction::create([
            'transaction_number' => 'KAS-DUP-A-'.uniqid(),
            'cashier_session_id' => $session->id,
            'type' => 'EXPENSE',
            'category' => 'Pencairan Pinjaman',
            'amount' => 1_000_000,
            'transaction_date' => now()->format('Y-m-d'),
            'transacted_at' => now(),
            'processed_by' => $this->user->id,
            'status' => 'POSTED',
            'source_type' => LoanDisbursement::class,
            'source_id' => $disbursement->id,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        CashTransaction::create([
            'transaction_number' => 'KAS-DUP-B-'.uniqid(),
            'cashier_session_id' => $session->id,
            'type' => 'EXPENSE',
            'category' => 'Pencairan Pinjaman',
            'amount' => 1_000_000,
            'transaction_date' => now()->format('Y-m-d'),
            'transacted_at' => now(),
            'processed_by' => $this->user->id,
            'status' => 'POSTED',
            'source_type' => LoanDisbursement::class,
            'source_id' => $disbursement->id,
        ]);
    }

    public function test_cash_reconciliation_ignores_legitimate_reversals(): void
    {
        // Sesi dengan transaksi yang DIREVERSED secara sah tidak boleh
        // menghasilkan temuan.
        $session = $this->openSession(1_000_000);

        $this->actingAs($this->user)->post(route('kasir.penerimaan.store'), [
            'amount' => 100_000,
            'category' => 'Pendapatan Lain-lain',
            'transaction_date' => now()->format('Y-m-d'),
        ]);

        $transaction = CashTransaction::where('cashier_session_id', $session->id)->first();
        $this->cashier()->reverse($transaction->fresh(), 'Salah input', $this->user->id);

        $result = (new CashReconciliationChecker)->check(now()->format('Y-m-d'), now()->format('Y-m-d'));

        $this->assertSame([], array_values(array_filter($result['findings'], fn ($f) => $f['object_id'] === $session->id)));
    }

    public function test_cash_reconciliation_is_read_only(): void
    {
        $session = $this->openSession(1_000_000);
        $this->actingAs($this->user)->post(route('kasir.penerimaan.store'), [
            'amount' => 100_000,
            'category' => 'Pendapatan Lain-lain',
            'transaction_date' => now()->format('Y-m-d'),
        ]);

        $sessionBefore = $session->fresh()->toArray();
        $transactionsBefore = CashTransaction::orderBy('id')->get()->map(fn ($t) => $t->only(['id', 'status', 'amount']))->all();

        (new CashReconciliationChecker)->check(now()->format('Y-m-d'), now()->format('Y-m-d'));

        $this->assertSame($sessionBefore, $session->fresh()->toArray());
        $this->assertSame($transactionsBefore, CashTransaction::orderBy('id')->get()->map(fn ($t) => $t->only(['id', 'status', 'amount']))->all());
    }

    public function test_operational_cash_is_not_confused_with_accounting_ledger(): void
    {
        // Batas akuntansi: transaksi kas operasional tidak membuat jurnal.
        $session = $this->openSession(1_000_000);

        $this->actingAs($this->user)->post(route('kasir.penerimaan.store'), [
            'amount' => 250_000,
            'category' => 'Pendapatan Lain-lain',
            'transaction_date' => now()->format('Y-m-d'),
        ]);

        $this->actingAs($this->user)->post(route('kasir.setoran.store'), [
            'amount' => 100_000,
            'transaction_date' => now()->format('Y-m-d'),
            'description' => 'Setoran ke kas pusat',
        ]);

        $this->assertSame(0, JournalEntry::count());
        $this->assertSame(0, JournalLine::count());

        // Sumber data kas operasional = cash_transactions POSTED (bukan jurnal).
        // 1jt (awal) + 250rb (penerimaan) - 100rb (setoran internal) = 1,15jt.
        $this->assertSame(1_150_000.0, $this->cashier()->totals($session->fresh())['closing_balance']);
    }
}
