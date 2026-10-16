<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\SavingsAccount;
use App\Models\ShmLoan;
use App\Models\User;
use App\Services\Accounting\JournalService;
use App\Services\CashierService;
use App\Services\LoanService;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T106 — validasi tanggal transaksi finansial.
 *
 * Kontrak: transaction_date/payment_date/etc <= tanggal aplikasi (server,
 * timezone proyek) ditolak oleh backend; tanggal historis (backdate)
 * tetap diizinkan; error validasi dikembalikan melalui mekanisme Inertia
 * existing (redirect back +withErrors).
 */
class TransactionDateValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    private JournalService $journals;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(ChartOfAccountSeeder::class);

        $this->superadmin = User::factory()->superadmin()->create();
        $this->journals = app(JournalService::class);
    }

    private function tomorrow(): string
    {
        return now()->addDay()->toDateString();
    }

    private function openCashierSession(): CashierSession
    {
        $this->actingAs($this->superadmin)->post(route('kasir.sesi.store'), [
            'operational_date' => now()->toDateString(),
            'opening_balance' => 100000,
        ])->assertRedirect();

        return CashierSession::where('opened_by', $this->superadmin->id)->firstOrFail();
    }

    private function disbursedLoan(): Loan
    {
        $loan = Loan::factory()->approved()->create();

        app(LoanService::class)->disburse($loan, [
            'disbursement_date' => now()->toDateString(),
            'first_due_date' => now()->addMonth()->toDateString(),
            'method' => 'TRANSFER',
        ], $this->superadmin->id);

        return $loan->fresh();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function balancedJournalLines(float $amount = 500_000): array
    {
        return [
            ['account_id' => $this->accountId('1-1100'), 'debit' => $amount, 'credit' => 0, 'description' => 'Penerimaan kas'],
            ['account_id' => $this->accountId('4-1300'), 'debit' => 0, 'credit' => $amount, 'description' => 'Pendapatan lain'],
        ];
    }

    private function accountId(string $code): int
    {
        return (int) ChartOfAccount::query()->where('code', $code)->value('id');
    }

    // =====================================================================
    // FUTURE DATE DITOLAK OLEH BACKEND
    // =====================================================================

    public function test_future_transaction_date_is_rejected_for_savings_deposit(): void
    {
        $account = SavingsAccount::factory()->create();

        $this->actingAs($this->superadmin)
            ->post(route('simpanan-transaksi.deposit.store', $account->id), [
                'amount' => 500000,
                'transaction_date' => $this->tomorrow(),
                'description' => 'Setoran uji tanggal future',
            ])
            ->assertSessionHasErrors([
                'transaction_date' => 'Tanggal transaksi tidak boleh melebihi tanggal hari ini.',
            ]);

        $this->assertDatabaseCount('savings_transactions', 0);
        $this->assertSame(0.0, (float) $account->fresh()->balance);
    }

    public function test_future_transaction_date_is_rejected_for_savings_withdrawal(): void
    {
        $account = SavingsAccount::factory()->withBalance(100000)->create();

        $this->actingAs($this->superadmin)
            ->post(route('simpanan-transaksi.withdraw.store', $account->id), [
                'amount' => 50000,
                'transaction_date' => $this->tomorrow(),
                'description' => 'Penarikan uji tanggal future',
            ])
            ->assertSessionHasErrors(['transaction_date']);

        $this->assertDatabaseCount('savings_transactions', 0);
        $this->assertSame(100000.0, (float) $account->fresh()->balance);
    }

    public function test_future_transaction_date_is_rejected_for_cashier_income(): void
    {
        $this->openCashierSession();

        $this->actingAs($this->superadmin)
            ->post(route('kasir.penerimaan.store'), [
                'amount' => 50000,
                'category' => 'Pendapatan Lain-lain',
                'transaction_date' => $this->tomorrow(),
                'description' => 'Penerimaan uji tanggal future',
            ])
            ->assertSessionHasErrors(['transaction_date']);

        $this->assertDatabaseCount('cash_transactions', 0);
    }

    public function test_future_transaction_date_is_rejected_for_cashier_expense(): void
    {
        $this->openCashierSession();

        $this->actingAs($this->superadmin)
            ->post(route('kasir.pengeluaran.store'), [
                'amount' => 10000,
                'category' => config('cashier.categories.expense')[0],
                'transaction_date' => $this->tomorrow(),
                'description' => 'Pengeluaran uji tanggal future',
            ])
            ->assertSessionHasErrors(['transaction_date']);

        $this->assertDatabaseCount('cash_transactions', 0);
    }

    public function test_future_transaction_date_is_rejected_for_cashier_cash_deposit(): void
    {
        $this->openCashierSession();

        $this->actingAs($this->superadmin)
            ->post(route('kasir.setoran.store'), [
                'amount' => 25000,
                'transaction_date' => $this->tomorrow(),
                'description' => 'Setoran kas uji tanggal future',
            ])
            ->assertSessionHasErrors(['transaction_date']);

        $this->assertDatabaseCount('cash_transactions', 0);
    }

    public function test_future_operational_date_is_rejected_for_cashier_session(): void
    {
        $this->actingAs($this->superadmin)
            ->post(route('kasir.sesi.store'), [
                'operational_date' => $this->tomorrow(),
                'opening_balance' => 100000,
            ])
            ->assertSessionHasErrors([
                'operational_date' => 'Tanggal operasional tidak boleh melebihi tanggal hari ini.',
            ]);

        $this->assertDatabaseCount('cashier_sessions', 0);
    }

    public function test_future_payment_date_is_rejected_for_installment_payment(): void
    {
        $loan = $this->disbursedLoan();

        $this->actingAs($this->superadmin)
            ->post(route('angsuran.pay.store', $loan->id), [
                'amount' => 100000,
                'payment_date' => $this->tomorrow(),
                'notes' => 'Pembayaran uji tanggal future',
            ])
            ->assertSessionHasErrors([
                'payment_date' => 'Tanggal pembayaran tidak boleh melebihi tanggal hari ini.',
            ]);

        $this->assertDatabaseCount('installment_payments', 0);
    }

    public function test_future_payment_date_is_rejected_for_shm_loan_payment(): void
    {
        $shmLoan = ShmLoan::factory()->create();

        $this->actingAs($this->superadmin)
            ->post(route('shm.loans.payments.store'), [
                'loan_id' => $shmLoan->id,
                'payment_date' => $this->tomorrow(),
                'amount' => 500000,
            ])
            ->assertSessionHasErrors([
                'payment_date' => 'Tanggal pembayaran tidak boleh melebihi tanggal hari ini.',
            ]);

        $this->assertDatabaseCount('shm_loan_payments', 0);
    }

    public function test_future_transaction_date_is_rejected_for_journal_store(): void
    {
        $this->actingAs($this->superadmin)
            ->post(route('akuntansi.jurnal.store'), [
                'transaction_date' => $this->tomorrow(),
                'description' => 'Jurnal uji tanggal future',
                'lines' => $this->balancedJournalLines(),
            ])
            ->assertSessionHasErrors([
                'transaction_date' => 'Tanggal transaksi tidak boleh melebihi tanggal hari ini.',
            ]);

        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_future_transaction_date_is_rejected_for_draft_journal_update(): void
    {
        $entry = $this->journals->createDraft(
            ['transaction_date' => now()->toDateString()],
            $this->balancedJournalLines(),
            $this->superadmin->id
        );

        $this->actingAs($this->superadmin)
            ->put(route('akuntansi.jurnal.update', $entry->id), [
                'transaction_date' => $this->tomorrow(),
                'description' => 'Draft diubah ke tanggal future',
                'lines' => $this->balancedJournalLines(),
            ])
            ->assertSessionHasErrors(['transaction_date']);

        $this->assertTrue(
            DB::table('journal_entries')
                ->where('id', $entry->id)
                ->whereDate('transaction_date', now()->toDateString())
                ->exists()
        );
        $this->assertSame(JournalEntry::STATUS_DRAFT, $entry->fresh()->status);
    }

    public function test_future_disbursement_date_is_rejected(): void
    {
        $loan = Loan::factory()->approved()->create();

        $this->actingAs($this->superadmin)
            ->post(route('pinjaman.disburse.store', $loan->id), [
                'disbursement_date' => $this->tomorrow(),
                'first_due_date' => now()->addMonth()->toDateString(),
                'method' => 'CASH',
            ])
            ->assertSessionHasErrors([
                'disbursement_date' => 'Tanggal pencairan tidak boleh melebihi tanggal hari ini.',
            ]);

        $this->assertDatabaseCount('loan_disbursements', 0);
        $this->assertSame('APPROVED', $loan->fresh()->status);
    }

    // =====================================================================
    // TANGGAL HARI INI DAN TANGGAL HISTORIS TETAP DIPROSES
    // =====================================================================

    public function test_current_date_deposit_is_processed(): void
    {
        $account = SavingsAccount::factory()->create();

        $this->actingAs($this->superadmin)
            ->post(route('simpanan-transaksi.deposit.store', $account->id), [
                'amount' => 500000,
                'transaction_date' => now()->toDateString(),
                'description' => 'Setoran hari ini',
            ])
            ->assertRedirect();

        $this->assertSame(500000.0, (float) $account->fresh()->balance);
        $this->assertTrue(
            DB::table('savings_transactions')
                ->where('savings_account_id', $account->id)
                ->where('type', 'deposit')
                ->whereDate('transaction_date', now()->toDateString())
                ->exists()
        );
    }

    public function test_current_date_cashier_income_is_processed(): void
    {
        $session = $this->openCashierSession();

        $this->actingAs($this->superadmin)
            ->post(route('kasir.penerimaan.store'), [
                'amount' => 50000,
                'category' => 'Pendapatan Lain-lain',
                'transaction_date' => now()->toDateString(),
                'description' => 'Penerimaan hari ini',
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('cash_transactions', 1);
        $closingBalance = app(CashierService::class)->totals($session->fresh())['closing_balance'];
        $this->assertSame(150000.0, (float) $closingBalance);
    }

    public function test_historical_backdated_deposit_is_still_processed(): void
    {
        $account = SavingsAccount::factory()->create();
        $historicalDate = now()->subDays(3)->toDateString();

        $this->actingAs($this->superadmin)
            ->post(route('simpanan-transaksi.deposit.store', $account->id), [
                'amount' => 200000,
                'transaction_date' => $historicalDate,
                'description' => 'Setoran historis',
            ])
            ->assertRedirect();

        $this->assertSame(200000.0, (float) $account->fresh()->balance);
        $this->assertTrue(
            DB::table('savings_transactions')
                ->where('savings_account_id', $account->id)
                ->where('type', 'deposit')
                ->whereDate('transaction_date', $historicalDate)
                ->exists()
        );
    }

    public function test_historical_backdated_disbursement_is_still_processed(): void
    {
        $loan = Loan::factory()->approved()->create();
        $historicalDate = now()->subDays(3)->toDateString();

        $this->actingAs($this->superadmin)
            ->post(route('pinjaman.disburse.store', $loan->id), [
                'disbursement_date' => $historicalDate,
                'first_due_date' => now()->addMonth()->toDateString(),
                'method' => 'CASH',
            ])
            ->assertRedirect();

        $this->assertTrue(
            DB::table('loan_disbursements')
                ->where('loan_id', $loan->id)
                ->whereDate('disbursement_date', $historicalDate)
                ->exists()
        );
        $this->assertSame('ACTIVE', $loan->fresh()->status);
    }

    // =====================================================================
    // FEEDBACK: ERROR KEMBALI KE UI MELALUI MEKANISME INERTIA EXISTING
    // =====================================================================

    public function test_validation_errors_are_rendered_by_relevant_forms(): void
    {
        // Kontrak Inertia: gagal validasi -> redirect back +withErrors.
        $account = SavingsAccount::factory()->create();
        $this->actingAs($this->superadmin)
            ->post(route('simpanan-transaksi.deposit.store', $account->id), [
                'amount' => 500000,
                'transaction_date' => $this->tomorrow(),
            ])
            ->assertSessionHasErrors('transaction_date');

        // Form terkait merender key error yang sama.
        $forms = [
            'Pages/Simpanan/Transaksi/Deposit.jsx' => 'errors.transaction_date',
            'Pages/Simpanan/Transaksi/Withdraw.jsx' => 'errors.transaction_date',
            'Pages/Akuntansi/Jurnal/Create.jsx' => 'errors.transaction_date',
            'Pages/Angsuran/Pay.jsx' => 'errors.payment_date',
            'Pages/Kasir/Transaksi/Income.jsx' => 'errors.transaction_date',
            'Pages/Kasir/Sesi/Create.jsx' => 'errors.operational_date',
            'Pages/Pinjaman/Disburse.jsx' => 'errors.disbursement_date',
        ];

        foreach ($forms as $file => $errorKey) {
            $source = file_get_contents(resource_path('js/'.$file));
            $this->assertStringContainsString(
                $errorKey,
                $source,
                "{$file} harus merender {$errorKey} dari backend."
            );
        }
    }

    // =====================================================================
    // REVERSAL: ERROR VALIDASI DAPAT DIKEMBALIKAN DAN DITAMPILKAN
    // =====================================================================

    public function test_reversal_reason_validation_error_is_returned_to_ui(): void
    {
        $entry = $this->journals->createDraft(
            ['transaction_date' => now()->toDateString()],
            $this->balancedJournalLines(),
            $this->superadmin->id
        );

        $this->actingAs($this->superadmin)
            ->post(route('akuntansi.jurnal.reverse', $entry->id), [
                'reason' => 'abc',
            ])
            ->assertSessionHasErrors('reason');

        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_reversal_service_error_is_returned_to_ui(): void
    {
        $entry = $this->journals->createDraft(
            ['transaction_date' => now()->toDateString()],
            $this->balancedJournalLines(),
            $this->superadmin->id
        );

        // Jurnal DRAFT tidak dapat direversi — error service dikembalikan.
        $this->actingAs($this->superadmin)
            ->post(route('akuntansi.jurnal.reverse', $entry->id), [
                'reason' => 'Pembatalan draft yang tidak valid',
            ])
            ->assertSessionHasErrors('status');

        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertSame('DRAFT', $entry->fresh()->status);
    }

    public function test_reversal_ui_displays_backend_errors(): void
    {
        $jurnalShow = file_get_contents(resource_path('js/Pages/Akuntansi/Jurnal/Show.jsx'));
        $this->assertStringContainsString('errors.reason', $jurnalShow);
        $this->assertStringContainsString('errors.status', $jurnalShow);

        $angsuranShow = file_get_contents(resource_path('js/Pages/Angsuran/ShowPayment.jsx'));
        $this->assertStringContainsString('errors.reason', $angsuranShow);
    }
}
