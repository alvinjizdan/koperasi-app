<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\CashTransaction;
use App\Models\InstallmentPayment;
use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Services\CashierService;
use App\Services\Reports\ReportPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CashierTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // Superadmin untuk melewati permission middleware pada seluruh route kasir.
        $this->user = User::factory()->superadmin()->create();
    }

    private function cashier(): CashierService
    {
        return app(CashierService::class);
    }

    private function openSession(float $openingBalance = 100000, ?User $operator = null): CashierSession
    {
        $operator ??= $this->user;

        $this->actingAs($operator)->post(route('kasir.sesi.store'), [
            'operational_date' => ReportPeriod::todayDate(),
            'opening_balance' => $openingBalance,
        ]);

        return CashierSession::where('opened_by', $operator->id)->firstOrFail();
    }

    private function sessionBalance(CashierSession $session): float
    {
        return $this->cashier()->totals($session->fresh())['closing_balance'];
    }

    /** 1. Pembukaan sesi kasir berhasil */
    public function test_can_open_cashier_session(): void
    {
        $response = $this->actingAs($this->user)->post(route('kasir.sesi.store'), [
            'operational_date' => now()->format('Y-m-d'),
            'opening_balance' => 250000,
            'opening_notes' => 'Kas awal shift pagi',
        ]);

        $session = CashierSession::where('opened_by', $this->user->id)->first();

        $response->assertRedirect(route('kasir.index'));
        $this->assertNotNull($session);
        $this->assertSame(CashierSession::STATUS_OPEN, $session->status);
        $this->assertEquals(250000.00, (float) $session->opening_balance);
        $this->assertEquals($this->user->id, $session->opened_by);
        $this->assertNotNull($session->opened_at);
        $this->assertStringStartsWith('SESI-', $session->session_number);
    }

    /** 2. Sesi aktif tidak dapat dibuka dua kali secara tidak sengaja */
    public function test_active_session_cannot_be_opened_twice(): void
    {
        $session = $this->openSession(300000);

        $response = $this->actingAs($this->user)->post(route('kasir.sesi.store'), [
            'operational_date' => now()->format('Y-m-d'),
            'opening_balance' => 999000,
        ]);

        $response->assertSessionHasErrors(['operational_date']);
        $this->assertSame(
            1,
            CashierSession::where('opened_by', $this->user->id)->where('status', CashierSession::STATUS_OPEN)->count()
        );
        $this->assertEquals(300000.00, (float) $session->fresh()->opening_balance);
    }

    /** Saldo awal negatif ditolak */
    public function test_opening_balance_cannot_be_negative(): void
    {
        $response = $this->actingAs($this->user)->post(route('kasir.sesi.store'), [
            'operational_date' => now()->format('Y-m-d'),
            'opening_balance' => -50000,
        ]);

        $response->assertSessionHasErrors(['opening_balance']);
        $this->assertSame(0, CashierSession::count());
    }

    /** 3. Penerimaan kas menambah saldo sesuai nominal */
    public function test_income_increases_session_balance(): void
    {
        $session = $this->openSession(100000);

        $response = $this->actingAs($this->user)->post(route('kasir.penerimaan.store'), [
            'amount' => 50000,
            'category' => 'Pendapatan Lain-lain',
            'transaction_date' => now()->format('Y-m-d'),
            'description' => 'Penerimaan kas uji',
        ]);

        $transaction = CashTransaction::where('type', CashTransaction::TYPE_INCOME)->first();

        $response->assertRedirect(route('kasir.transaksi.show', $transaction->id));
        $this->assertEquals(50000.00, (float) $transaction->amount);
        $this->assertEquals($session->id, $transaction->cashier_session_id);
        $this->assertEquals($this->user->id, $transaction->processed_by);
        $this->assertEquals(150000.00, $this->sessionBalance($session));
    }

    /** 4. Pengeluaran kas mengurangi saldo sesuai nominal */
    public function test_expense_decreases_session_balance(): void
    {
        $session = $this->openSession(100000);

        $this->actingAs($this->user)
            ->post(route('kasir.pengeluaran.store'), [
                'amount' => 40000,
                'category' => 'Biaya Operasional',
                'transaction_date' => now()->format('Y-m-d'),
                'description' => 'Pembelian ATK',
                'document_reference' => 'NOTA-001',
            ])
            ->assertRedirect();

        $transaction = CashTransaction::where('type', CashTransaction::TYPE_EXPENSE)->first();

        $this->assertEquals(40000.00, (float) $transaction->amount);
        $this->assertSame('NOTA-001', $transaction->document_reference);
        $this->assertEquals(60000.00, $this->sessionBalance($session));
    }

    /** 5. Pengeluaran yang melebihi saldo ditolak */
    public function test_expense_exceeding_cash_balance_is_rejected(): void
    {
        $session = $this->openSession(100000);

        $response = $this->actingAs($this->user)->post(route('kasir.pengeluaran.store'), [
            'amount' => 150000,
            'category' => 'Biaya Operasional',
            'transaction_date' => now()->format('Y-m-d'),
        ]);

        $response->assertSessionHasErrors(['amount']);
        $this->assertSame(0, CashTransaction::count());
        $this->assertEquals(100000.00, $this->sessionBalance($session));
    }

    /** 6. Nominal nol atau negatif ditolak */
    public function test_zero_or_negative_amount_is_rejected(): void
    {
        $session = $this->openSession(100000);

        $incomeResponse = $this->actingAs($this->user)->post(route('kasir.penerimaan.store'), [
            'amount' => 0,
            'category' => 'Pendapatan Lain-lain',
            'transaction_date' => now()->format('Y-m-d'),
        ]);
        $incomeResponse->assertSessionHasErrors(['amount']);

        $expenseResponse = $this->actingAs($this->user)->post(route('kasir.pengeluaran.store'), [
            'amount' => -25000,
            'category' => 'Biaya Operasional',
            'transaction_date' => now()->format('Y-m-d'),
        ]);
        $expenseResponse->assertSessionHasErrors(['amount']);

        $this->assertSame(0, CashTransaction::count());
        $this->assertEquals(100000.00, $this->sessionBalance($session));
    }

    /** 7. Setoran kasir tercatat dengan benar */
    public function test_cashier_deposit_is_recorded(): void
    {
        $session = $this->openSession(500000);

        $this->actingAs($this->user)
            ->post(route('kasir.setoran.store'), [
                'amount' => 200000,
                'transaction_date' => now()->format('Y-m-d'),
                'description' => 'Setoran ke kas pusat oleh petugas',
            ])
            ->assertRedirect();

        $transaction = CashTransaction::where('type', CashTransaction::TYPE_DEPOSIT)->first();

        $this->assertNotNull($transaction);
        $this->assertEquals(200000.00, (float) $transaction->amount);
        $this->assertSame(config('cashier.deposit.category'), $transaction->category);
        $this->assertStringStartsWith('KSETOR-', $transaction->transaction_number);
        $this->assertEquals(300000.00, $this->sessionBalance($session));
    }

    /** 8. Penutupan kas menghitung saldo akhir sistem dengan benar */
    public function test_closing_calculates_system_balance(): void
    {
        $session = $this->openSession(100000);

        $this->actingAs($this->user)->post(route('kasir.penerimaan.store'), [
            'amount' => 50000,
            'category' => 'Pendapatan Lain-lain',
            'transaction_date' => now()->format('Y-m-d'),
        ]);
        $this->actingAs($this->user)->post(route('kasir.pengeluaran.store'), [
            'amount' => 20000,
            'category' => 'Biaya Operasional',
            'transaction_date' => now()->format('Y-m-d'),
        ]);
        $this->actingAs($this->user)->post(route('kasir.setoran.store'), [
            'amount' => 30000,
            'transaction_date' => now()->format('Y-m-d'),
            'description' => 'Setoran kas pusat',
        ]);

        $response = $this->actingAs($this->user)->post(route('kasir.sesi.close.store', $session->id), [
            'physical_closing_balance' => 100000,
        ]);

        $session->refresh();

        $response->assertRedirect(route('kasir.sesi.show', $session->id));
        $this->assertSame(CashierSession::STATUS_CLOSED, $session->status);
        $this->assertEquals(50000.00, (float) $session->total_income);
        $this->assertEquals(20000.00, (float) $session->total_expense);
        $this->assertEquals(30000.00, (float) $session->total_deposit);
        $this->assertEquals(100000.00, (float) $session->system_closing_balance);
        $this->assertEquals(100000.00, (float) $session->physical_closing_balance);
        $this->assertEquals(0.00, (float) $session->difference);
        $this->assertEquals($this->user->id, $session->closed_by);
        $this->assertNotNull($session->closed_at);
    }

    /** 9. Selisih kas tercatat dengan benar */
    public function test_closing_records_cash_difference(): void
    {
        $session = $this->openSession(100000);

        $this->actingAs($this->user)->post(route('kasir.penerimaan.store'), [
            'amount' => 50000,
            'category' => 'Pendapatan Lain-lain',
            'transaction_date' => now()->format('Y-m-d'),
        ]);

        $this->actingAs($this->user)->post(route('kasir.sesi.close.store', $session->id), [
            'physical_closing_balance' => 140000,
            'closing_notes' => 'Kurang setor Rp 10.000, sedang ditelusuri.',
        ]);

        $session->refresh();

        $this->assertEquals(150000.00, (float) $session->system_closing_balance);
        $this->assertEquals(-10000.00, (float) $session->difference);
        $this->assertSame('Kurang setor Rp 10.000, sedang ditelusuri.', $session->closing_notes);
    }

    /** Selisih kas tanpa catatan penjelasan ditolak */
    public function test_closing_with_difference_requires_notes(): void
    {
        $session = $this->openSession(100000);

        $response = $this->actingAs($this->user)->post(route('kasir.sesi.close.store', $session->id), [
            'physical_closing_balance' => 90000,
        ]);

        $response->assertSessionHasErrors(['closing_notes']);
        $this->assertSame(CashierSession::STATUS_OPEN, $session->fresh()->status);
    }

    /** Penutupan ganda ditolak */
    public function test_session_cannot_be_closed_twice(): void
    {
        $session = $this->openSession(100000);

        $this->actingAs($this->user)->post(route('kasir.sesi.close.store', $session->id), [
            'physical_closing_balance' => 100000,
        ]);

        $response = $this->actingAs($this->user)->post(route('kasir.sesi.close.store', $session->id), [
            'physical_closing_balance' => 90000,
            'closing_notes' => 'Penutupan ulang',
        ]);

        $response->assertSessionHasErrors(['status']);
        $this->assertEquals(100000.00, (float) $session->fresh()->physical_closing_balance);
    }

    /** 10. Sesi yang ditutup tidak menerima transaksi baru */
    public function test_closed_session_rejects_new_transactions(): void
    {
        $session = $this->openSession(100000);

        $this->actingAs($this->user)->post(route('kasir.sesi.close.store', $session->id), [
            'physical_closing_balance' => 100000,
        ]);

        $this->actingAs($this->user)
            ->post(route('kasir.penerimaan.store'), [
                'amount' => 50000,
                'category' => 'Pendapatan Lain-lain',
                'transaction_date' => now()->format('Y-m-d'),
            ])
            ->assertRedirect(route('kasir.index'));

        $this->assertSame(0, CashTransaction::count());

        $this->expectException(ValidationException::class);

        $this->cashier()->recordIncome($session->fresh(), [
            'amount' => 50000,
            'category' => 'Pendapatan Lain-lain',
            'transaction_date' => now()->format('Y-m-d'),
        ], $this->user->id);
    }

    /** 11. Request transaksi berulang tidak menghasilkan transaksi ganda */
    public function test_duplicate_request_with_same_idempotency_key_is_ignored(): void
    {
        $session = $this->openSession(100000);
        $key = 'idempotency-kas-0001';

        $payload = [
            'amount' => 25000,
            'category' => 'Pendapatan Lain-lain',
            'transaction_date' => now()->format('Y-m-d'),
            'idempotency_key' => $key,
        ];

        $this->actingAs($this->user)->post(route('kasir.penerimaan.store'), $payload);
        $this->actingAs($this->user)->post(route('kasir.penerimaan.store'), $payload);

        $this->assertSame(1, CashTransaction::where('idempotency_key', $key)->count());
        $this->assertEquals(125000.00, $this->sessionBalance($session));
    }

    /** 12. Transaksi gagal tidak mengubah saldo secara parsial */
    public function test_failed_transaction_does_not_change_balance_partially(): void
    {
        $session = $this->openSession(100000);

        $this->actingAs($this->user)->post(route('kasir.pengeluaran.store'), [
            'amount' => 500000,
            'category' => 'Biaya Operasional',
            'transaction_date' => now()->format('Y-m-d'),
        ]);

        $this->assertSame(0, CashTransaction::count());
        $this->assertEquals(0.0, (float) CashTransaction::sum('amount'));
        $this->assertEquals(100000.00, $this->sessionBalance($session));
    }

    /** 13a. Setoran simpanan tunai tercermin sebagai penerimaan kas (tanpa dampak ganda) */
    public function test_savings_deposit_creates_single_cash_impact(): void
    {
        $account = SavingsAccount::factory()->create(['balance' => 0]);
        $session = $this->openSession(100000);
        $key = 'idempotency-simpanan-01';

        $payload = [
            'amount' => 75000,
            'transaction_date' => now()->format('Y-m-d'),
            'description' => 'Setoran simpanan tunai',
            'idempotency_key' => $key,
        ];

        $this->actingAs($this->user)->post(route('simpanan-transaksi.deposit.store', $account->id), $payload);
        $this->actingAs($this->user)->post(route('simpanan-transaksi.deposit.store', $account->id), $payload);

        $this->assertSame(1, SavingsTransaction::count());
        $this->assertSame(1, CashTransaction::count());

        $cash = CashTransaction::first();
        $this->assertSame(CashTransaction::TYPE_INCOME, $cash->type);
        $this->assertSame(SavingsTransaction::class, $cash->source_type);
        $this->assertEquals(SavingsTransaction::first()->id, $cash->source_id);
        $this->assertEquals(175000.00, $this->sessionBalance($session));
    }

    /** 13b. Penarikan simpanan tunai tercermin sebagai pengeluaran kas */
    public function test_savings_withdrawal_creates_cash_expense(): void
    {
        $account = SavingsAccount::factory()->create(['balance' => 200000]);
        $session = $this->openSession(100000);

        $this->actingAs($this->user)->post(route('simpanan-transaksi.withdraw.store', $account->id), [
            'amount' => 50000,
            'transaction_date' => now()->format('Y-m-d'),
            'description' => 'Penarikan tunai',
        ]);

        $cash = CashTransaction::first();

        $this->assertNotNull($cash);
        $this->assertSame(CashTransaction::TYPE_EXPENSE, $cash->type);
        $this->assertSame(SavingsTransaction::class, $cash->source_type);
        $this->assertEquals(50000.00, $this->sessionBalance($session));
    }

    /** 13c. Pembayaran angsuran tunai tercermin sebagai penerimaan kas */
    public function test_installment_payment_creates_cash_income(): void
    {
        $loan = Loan::factory()->approved()->create(['amount' => 6000000, 'tenor_months' => 6]);

        // Pencairan non-tunai tidak boleh memengaruhi saldo kas fisik.
        $this->actingAs($this->user)->post(route('pinjaman.disburse.store', $loan->id), [
            'disbursement_date' => now()->format('Y-m-d'),
            'first_due_date' => now()->addMonth()->format('Y-m-d'),
            'method' => 'TRANSFER',
        ]);

        $this->assertSame(0, CashTransaction::count());

        $session = $this->openSession(100000);

        $this->actingAs($this->user)->post(route('angsuran.pay.store', $loan->id), [
            'amount' => 1000000,
            'payment_date' => now()->format('Y-m-d'),
        ]);

        $cash = CashTransaction::first();

        $this->assertNotNull($cash);
        $this->assertSame(CashTransaction::TYPE_INCOME, $cash->type);
        $this->assertSame(InstallmentPayment::class, $cash->source_type);
        $this->assertEquals(1100000.00, $this->sessionBalance($session));
    }

    /** 13d. Pencairan pinjaman tunai tercermin sebagai pengeluaran kas */
    public function test_loan_disbursement_creates_cash_expense(): void
    {
        $loan = Loan::factory()->approved()->create(['amount' => 3000000, 'tenor_months' => 3]);
        $session = $this->openSession(5000000);

        $this->actingAs($this->user)->post(route('pinjaman.disburse.store', $loan->id), [
            'disbursement_date' => now()->format('Y-m-d'),
            'first_due_date' => now()->addMonth()->format('Y-m-d'),
            'method' => 'CASH',
        ]);

        $cash = CashTransaction::first();

        $this->assertNotNull($cash);
        $this->assertSame(CashTransaction::TYPE_EXPENSE, $cash->type);
        $this->assertSame(LoanDisbursement::class, $cash->source_type);
        $this->assertEquals(2000000.00, $this->sessionBalance($session));
    }

    /** Tanpa sesi kasir terbuka tidak ada entri kas palsu yang dibuat */
    public function test_source_transaction_without_open_session_creates_no_cash_entry(): void
    {
        $loan = Loan::factory()->approved()->create(['amount' => 3000000, 'tenor_months' => 3]);

        $this->actingAs($this->user)->post(route('pinjaman.disburse.store', $loan->id), [
            'disbursement_date' => now()->format('Y-m-d'),
            'first_due_date' => now()->addMonth()->format('Y-m-d'),
            'method' => 'CASH',
        ]);

        $this->assertSame(CashTransaction::count(), 0);
        $this->assertSame('ACTIVE', $loan->fresh()->status);
    }

    /** Saldo kas tidak boleh negatif akibat transaksi modul sumber */
    public function test_source_expense_requires_sufficient_cash(): void
    {
        $loan = Loan::factory()->approved()->create(['amount' => 5000000, 'tenor_months' => 5]);
        $this->openSession(100000);

        $response = $this->actingAs($this->user)->post(route('pinjaman.disburse.store', $loan->id), [
            'disbursement_date' => now()->format('Y-m-d'),
            'first_due_date' => now()->addMonth()->format('Y-m-d'),
            'method' => 'CASH',
        ]);

        $response->assertSessionHasErrors(['amount']);
        $this->assertSame(0, CashTransaction::count());
        $this->assertFalse($loan->fresh()->disbursement()->exists());
        $this->assertSame('APPROVED', $loan->fresh()->status);
    }

    /** Koreksi transaksi kas tercatat sebagai pembatalan berjejak audit tanpa entri ganda */
    public function test_reversal_marks_transaction_as_void_and_is_audited(): void
    {
        $session = $this->openSession(100000);

        $this->actingAs($this->user)->post(route('kasir.penerimaan.store'), [
            'amount' => 50000,
            'category' => 'Pendapatan Lain-lain',
            'transaction_date' => now()->format('Y-m-d'),
        ]);

        $transaction = CashTransaction::first();
        $this->assertEquals(150000.00, $this->sessionBalance($session));

        $this->actingAs($this->user)
            ->post(route('kasir.transaksi.reverse', $transaction->id), [
                'reason' => 'Nominal salah input',
            ])
            ->assertRedirect();

        $transaction->refresh();

        $this->assertSame(CashTransaction::STATUS_REVERSED, $transaction->status);
        $this->assertEquals($this->user->id, $transaction->reversed_by);
        $this->assertSame('Nominal salah input', $transaction->reversal_reason);
        $this->assertNotNull($transaction->reversed_at);

        // Rekaman tetap ada (tidak dihapus fisik) dan tidak menghasilkan dampak kas ganda.
        $this->assertSame(1, CashTransaction::count());
        $this->assertSame(0, CashTransaction::posted()->count());
        $this->assertEquals(100000.00, $this->sessionBalance($session));

        // Koreksi berulang ditolak.
        $response = $this->actingAs($this->user)->post(route('kasir.transaksi.reverse', $transaction->id), [
            'reason' => 'Koreksi ulang',
        ]);
        $response->assertSessionHasErrors(['status']);
        $this->assertEquals(100000.00, $this->sessionBalance($session));
    }

    /** Transaksi dari modul lain tidak dapat dikoreksi dari modul kasir */
    public function test_reversal_is_rejected_for_source_transactions(): void
    {
        $account = SavingsAccount::factory()->create(['balance' => 0]);
        $this->openSession(100000);

        $this->actingAs($this->user)->post(route('simpanan-transaksi.deposit.store', $account->id), [
            'amount' => 50000,
            'transaction_date' => now()->format('Y-m-d'),
        ]);

        $cash = CashTransaction::first();

        $response = $this->actingAs($this->user)->post(route('kasir.transaksi.reverse', $cash->id), [
            'reason' => 'Percobaan koreksi dari kasir',
        ]);

        $response->assertSessionHasErrors(['status']);
        $this->assertSame(CashTransaction::STATUS_POSTED, $cash->fresh()->status);
        $this->assertSame(1, CashTransaction::count());
    }

    /** 14. Pengguna tanpa izin (unauthenticated) tidak dapat melakukan transaksi atau penutupan */
    public function test_unauthenticated_user_cannot_perform_cashier_actions(): void
    {
        $session = CashierSession::factory()->create();

        $this->get(route('kasir.index'))->assertRedirect(route('login'));
        $this->get(route('kasir.transaksi.index'))->assertRedirect(route('login'));
        $this->get(route('kasir.ringkasan.index'))->assertRedirect(route('login'));

        $this->post(route('kasir.sesi.store'), [
            'operational_date' => now()->format('Y-m-d'),
            'opening_balance' => 100000,
        ])->assertRedirect(route('login'));

        $this->post(route('kasir.penerimaan.store'), [
            'amount' => 50000,
            'category' => 'Pendapatan Lain-lain',
            'transaction_date' => now()->format('Y-m-d'),
        ])->assertRedirect(route('login'));

        $this->post(route('kasir.sesi.close.store', $session->id), [
            'physical_closing_balance' => 100000,
        ])->assertRedirect(route('login'));

        $this->assertSame(0, CashTransaction::count());
        $this->assertSame(CashierSession::STATUS_OPEN, $session->fresh()->status);
    }

    /** 15. Ringkasan kas sesuai dengan transaksi yang berhasil */
    public function test_daily_summary_matches_recorded_transactions(): void
    {
        $session = $this->openSession(100000);

        $this->actingAs($this->user)->post(route('kasir.penerimaan.store'), [
            'amount' => 50000,
            'category' => 'Pendapatan Lain-lain',
            'transaction_date' => now()->format('Y-m-d'),
        ]);
        $this->actingAs($this->user)->post(route('kasir.pengeluaran.store'), [
            'amount' => 20000,
            'category' => 'Biaya Operasional',
            'transaction_date' => now()->format('Y-m-d'),
        ]);

        $response = $this->actingAs($this->user)->get(route('kasir.ringkasan.index', [
            'date_from' => now()->format('Y-m-d'),
            'date_to' => now()->format('Y-m-d'),
        ]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Kasir/Ringkasan')
            ->where('summary.sessions_count', 1)
            ->where('summary.opening_balance', fn ($value) => (float) $value === 100000.0)
            ->where('summary.income_total', fn ($value) => (float) $value === 50000.0)
            ->where('summary.expense_total', fn ($value) => (float) $value === 20000.0)
            ->where('summary.deposit_total', fn ($value) => (float) $value === 0.0)
            ->where('summary.system_balance', fn ($value) => (float) $value === 130000.0)
            ->where('rows.data', fn ($rows) => count($rows) === 1
                && $rows[0]['session_number'] === $session->session_number
                && (float) $rows[0]['system_balance'] === 130000.0)
        );
    }

    /** Halaman kasir dapat diakses operator yang terautentikasi */
    public function test_cashier_pages_are_accessible(): void
    {
        $session = $this->openSession(100000);

        $this->actingAs($this->user)->get(route('kasir.index'))->assertOk();
        $this->actingAs($this->user)->get(route('kasir.sesi.index'))->assertOk();
        $this->actingAs($this->user)->get(route('kasir.sesi.show', $session->id))->assertOk();
        $this->actingAs($this->user)->get(route('kasir.sesi.close.create', $session->id))->assertOk();
        $this->actingAs($this->user)->get(route('kasir.sesi.create'))->assertRedirect(route('kasir.sesi.show', $session->id));
        $this->actingAs($this->user)->get(route('kasir.transaksi.index'))->assertOk();
        $this->actingAs($this->user)->get(route('kasir.ringkasan.index'))->assertOk();
    }

    /** Form transaksi kas memerlukan sesi kasir terbuka */
    public function test_cash_entry_forms_require_open_session(): void
    {
        $this->actingAs($this->user)
            ->get(route('kasir.penerimaan.create'))
            ->assertRedirect(route('kasir.index'));

        $this->actingAs($this->user)
            ->get(route('kasir.pengeluaran.create'))
            ->assertRedirect(route('kasir.index'));

        $this->actingAs($this->user)
            ->get(route('kasir.setoran.create'))
            ->assertRedirect(route('kasir.index'));
    }

    /** Dashboard menampilkan saldo kas dari sesi kasir yang terbuka */
    public function test_dashboard_reflects_open_session_cash_balance(): void
    {
        $this->openSession(100000);

        $this->actingAs($this->user)->post(route('kasir.penerimaan.store'), [
            'amount' => 50000,
            'category' => 'Pendapatan Lain-lain',
            'transaction_date' => now()->format('Y-m-d'),
        ]);

        $response = $this->actingAs($this->user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('metrics.cash.has_sessions', true)
            ->where('metrics.cash.opening_balance', fn ($value) => (float) $value === 100000.0)
            ->where('metrics.cash.income_total', fn ($value) => (float) $value === 50000.0)
            ->where('metrics.cash.closing_balance', fn ($value) => (float) $value === 150000.0)
            ->where('metrics.cash.open_sessions_count', fn ($value) => (int) $value === 1)
        );
    }

    /** Saldo kas konsisten dengan ledger transaksi */
    public function test_cash_balance_stays_consistent_with_ledger(): void
    {
        $session = $this->openSession(200000);

        $this->actingAs($this->user)->post(route('kasir.penerimaan.store'), [
            'amount' => 120000,
            'category' => 'Pendapatan Lain-lain',
            'transaction_date' => now()->format('Y-m-d'),
        ]);
        $this->actingAs($this->user)->post(route('kasir.pengeluaran.store'), [
            'amount' => 45000,
            'category' => 'Biaya Operasional',
            'transaction_date' => now()->format('Y-m-d'),
        ]);
        $this->actingAs($this->user)->post(route('kasir.setoran.store'), [
            'amount' => 25000,
            'transaction_date' => now()->format('Y-m-d'),
            'description' => 'Setoran kas pusat',
        ]);

        $income = (float) CashTransaction::where('type', CashTransaction::TYPE_INCOME)->sum('amount');
        $expense = (float) CashTransaction::where('type', CashTransaction::TYPE_EXPENSE)->sum('amount');
        $deposit = (float) CashTransaction::where('type', CashTransaction::TYPE_DEPOSIT)->sum('amount');
        $expected = 200000 + $income - $expense - $deposit;

        $this->assertEquals($expected, $this->sessionBalance($session));
        $this->assertEquals(250000.00, $expected);
    }
}
