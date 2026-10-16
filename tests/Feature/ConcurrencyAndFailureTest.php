<?php

namespace Tests\Feature;

use App\Models\CashierSession;
use App\Models\CashTransaction;
use App\Models\Loan;
use App\Models\Member;
use App\Models\SavingsAccount;
use App\Models\User;
use App\Services\LoanService;
use App\Services\SavingsTransactionService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\Concurrency\ConcurrencyHarness;
use Tests\TestCase;

/**
 * Pengujian konkurensi dan kegagalan (Ticket 10 bagian K).
 *
 * Semua skenario dijalankan sebagai PROSES PHP PARALEL (proc_open) dengan
 * koneksi PostgreSQL masing-masing, bukan request berurutan pada satu koneksi,
 * sehingga row locking, partial unique index, dan idempotensi benar-benar
 * diuji pada kondisi race nyata. Suite ini hanya berjalan bila driver pgsql
 * tersedia; pada environment tanpa pgsql test dilewati secara eksplisit
 * (bukan dianggap lulus tanpa pengujian).
 *
 * Trait RefreshDatabase sengaja TIDAK dipakai: suite ini membuat database
 * PostgreSQL terpisah per test (migrate + seed + drop) agar worker paralel
 * melihat data yang sama tanpa transaksi pengujian yang belum commit.
 */
#[RequiresPhpExtension('pdo_pgsql')]
class ConcurrencyAndFailureTest extends TestCase
{
    private ConcurrencyHarness $harness;

    private User $operator;

    private bool $concurrencyDatabaseReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->postgresAvailable()) {
            $this->markTestSkipped('PostgreSQL untuk pengujian konkurensi tidak tersedia.');
        }

        $this->harness = new ConcurrencyHarness($this);
        $this->harness->setUpDatabase();
        $this->concurrencyDatabaseReady = true;

        // Data dibuat pada koneksi pgsql pengujian konkurensi (sudah commit)
        // agar worker paralel dapat membacanya.
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->operator = User::factory()->superadmin()->create();
    }

    protected function tearDown(): void
    {
        if ($this->concurrencyDatabaseReady) {
            $this->harness->tearDownDatabase();
        }

        parent::tearDown();
    }

    /**
     * Dua setoran PARALEL pada rekening yang sama: keduanya sah dan saldo
     * akhir tepat jumlah kedua setoran (tidak ada update yang tertimpa).
     */
    public function test_parallel_deposits_on_same_account_both_apply(): void
    {
        $account = SavingsAccount::factory()->create(['balance' => 0]);

        $results = $this->harness->runParallel([
            ['task' => 'savings_deposit', 'params' => ['account_id' => $account->id, 'amount' => 100000, 'user_id' => $this->operator->id]],
            ['task' => 'savings_deposit', 'params' => ['account_id' => $account->id, 'amount' => 250000, 'user_id' => $this->operator->id]],
        ]);

        $results->assertAllOk();

        $account->refresh();
        $this->assertSame(350000.0, (float) $account->balance);
        $this->assertSame(2, $account->transactions()->where('status', 'completed')->count());
    }

    /**
     * Dua penarikan PARALEL pada rekening yang sama: lock memastikan urutan
     * eksekusi benar; hanya penarikan yang masih tertutup saldonya yang sah
     * dan saldo akhir TIDAK PERNAH negatif.
     */
    public function test_parallel_withdrawals_do_not_overdraw_account(): void
    {
        $account = SavingsAccount::factory()->create(['balance' => 200000]);

        $results = $this->harness->runParallel([
            ['task' => 'savings_withdraw', 'params' => ['account_id' => $account->id, 'amount' => 150000, 'user_id' => $this->operator->id]],
            ['task' => 'savings_withdraw', 'params' => ['account_id' => $account->id, 'amount' => 150000, 'user_id' => $this->operator->id]],
        ]);

        $results->assertNoPartialEffects();

        $account->refresh();
        $withdrawn = (float) $account->transactions()->where('type', 'withdrawal')->where('status', 'completed')->sum('amount');

        $this->assertGreaterThanOrEqual(0, (float) $account->balance, 'Saldo rekening tidak boleh negatif.');
        $this->assertSame(round(200000.0 - $withdrawn, 2), (float) $account->balance);
        $this->assertLessThanOrEqual(1, $account->transactions()->where('status', 'completed')->count());
    }

    /**
     * Setoran dan penarikan PARALEL bersamaan: hasil akhir konsisten dengan
     * ledger transaksi yang sah. Saldo awal dibentuk melalui transaksi nyata
     * (bukan menanam kolom balance) agar invariant saldo = ledger terjaga.
     */
    public function test_parallel_deposit_and_withdrawal_stay_consistent_with_ledger(): void
    {
        $account = SavingsAccount::factory()->create(['balance' => 0]);

        // Bentuk saldo awal 300.000 melalui transaksi sah (ledger konsisten).
        app(SavingsTransactionService::class)->deposit($account, [
            'amount' => 300000,
            'transaction_date' => now()->format('Y-m-d'),
            'processed_by' => $this->operator->id,
        ]);

        $results = $this->harness->runParallel([
            ['task' => 'savings_withdraw', 'params' => ['account_id' => $account->id, 'amount' => 200000, 'user_id' => $this->operator->id]],
            ['task' => 'savings_deposit', 'params' => ['account_id' => $account->id, 'amount' => 50000, 'user_id' => $this->operator->id]],
        ]);

        $results->assertAllOk();

        $account->refresh();
        $deposits = (float) $account->transactions()->where('type', 'deposit')->where('status', 'completed')->sum('amount');
        $withdrawals = (float) $account->transactions()->where('type', 'withdrawal')->where('status', 'completed')->sum('amount');

        $this->assertSame(round($deposits - $withdrawals, 2), (float) $account->balance);
        $this->assertSame(150000.0, (float) $account->balance);
    }

    /**
     * Idempotensi pada kondisi race: dua request dengan kunci sama dijalankan
     * PARALEL menghasilkan tepat satu transaksi dan satu dampak saldo.
     */
    public function test_same_idempotency_key_in_parallel_creates_single_transaction(): void
    {
        $account = SavingsAccount::factory()->create(['balance' => 0]);
        $key = 'race-key-'.uniqid();

        $results = $this->harness->runParallel([
            ['task' => 'savings_deposit', 'params' => ['account_id' => $account->id, 'amount' => 75000, 'user_id' => $this->operator->id, 'idempotency_key' => $key]],
            ['task' => 'savings_deposit', 'params' => ['account_id' => $account->id, 'amount' => 75000, 'user_id' => $this->operator->id, 'idempotency_key' => $key]],
        ]);

        // Kedua worker boleh sukses (yang satu menerima transaksi eksisting)
        // atau salah satu gagal validasi; yang dilarang adalah dampak ganda.
        $this->assertSame(1, DB::table('savings_transactions')->where('idempotency_key', $key)->count());

        $account->refresh();
        $this->assertSame(75000.0, (float) $account->balance);
    }

    /**
     * Pencairan ganda PARALEL: hanya satu LoanDisbursement yang tercipta dan
     * status pinjaman berubah sekali.
     */
    public function test_parallel_disbursement_creates_single_disbursement(): void
    {
        $loan = Loan::factory()->approved()->create(['amount' => 6000000, 'tenor_months' => 6]);

        $results = $this->harness->runParallel([
            ['task' => 'loan_disburse', 'params' => ['loan_id' => $loan->id, 'user_id' => $this->operator->id, 'method' => 'TRANSFER']],
            ['task' => 'loan_disburse', 'params' => ['loan_id' => $loan->id, 'user_id' => $this->operator->id, 'method' => 'TRANSFER']],
        ]);

        $successCount = $results->whereOk()->count();
        $this->assertSame(1, DB::table('loan_disbursements')->where('loan_id', $loan->id)->count());

        if ($successCount === 2) {
            $this->fail('Kedua worker berhasil mencairkan pinjaman yang sama (pencairan ganda).');
        }

        $loan->refresh();
        $this->assertSame('ACTIVE', $loan->status);
        $this->assertSame(6, $loan->schedules()->count());
    }

    /**
     * Dua pembayaran angsuran PARALEL pada pinjaman yang sama: alokasi jadwal
     * tidak saling menimpa dan total terbayar tepat.
     */
    public function test_parallel_installment_payments_allocate_correctly(): void
    {
        $loan = Loan::factory()->approved()->create(['amount' => 1200000, 'tenor_months' => 4]);
        app(LoanService::class)->disburse($loan, [
            'disbursement_date' => now()->format('Y-m-d'),
            'first_due_date' => now()->addMonth()->format('Y-m-d'),
            'method' => 'TRANSFER',
        ], $this->operator->id);

        $results = $this->harness->runParallel([
            ['task' => 'installment_pay', 'params' => ['loan_id' => $loan->id, 'amount' => 300000, 'user_id' => $this->operator->id]],
            ['task' => 'installment_pay', 'params' => ['loan_id' => $loan->id, 'amount' => 300000, 'user_id' => $this->operator->id]],
        ]);

        $results->assertAllOk();

        $loan->refresh();
        $totalPaid = (float) $loan->payments()->where('status', 'COMPLETED')->sum('amount');
        $this->assertSame(600000.0, $totalPaid);
        $this->assertSame(600000.0, round((float) $loan->schedules()->sum('paid_amount'), 2));
    }

    /**
     * Dua pengeluaran kas PARALEL pada sesi yang sama: saldo kas tidak pernah
     * negatif dan snapshot konsisten dengan ledger.
     */
    public function test_parallel_cash_expenses_respect_cash_balance(): void
    {
        $session = CashierSession::factory()->withOpeningBalance(400000)->create([
            'opened_by' => $this->operator->id,
        ]);

        $results = $this->harness->runParallel([
            ['task' => 'cash_expense', 'params' => ['session_id' => $session->id, 'amount' => 300000, 'user_id' => $this->operator->id]],
            ['task' => 'cash_expense', 'params' => ['session_id' => $session->id, 'amount' => 300000, 'user_id' => $this->operator->id]],
        ]);

        $results->assertNoPartialEffects();

        // Tepat satu pengeluaran sah: setelah yang pertama, saldo kas 100.000
        // tidak lagi mencukupi pengeluaran 300.000 berikutnya.
        $postedTotal = (float) $session->transactions()->where('status', CashTransaction::STATUS_POSTED)->sum('amount');
        $this->assertSame(300000.0, $postedTotal);

        $session->refresh();
        $this->assertSame(1, (int) $session->transactions()->count());
    }

    /**
     * Transaksi kas bersamaan dengan penutupan sesi: transaksi yang sah hanya
     * masuk sesi terbuka; snapshot penutupan tetap konsisten.
     */
    public function test_transaction_racing_with_session_close_is_consistent(): void
    {
        $session = CashierSession::factory()->withOpeningBalance(500000)->create([
            'opened_by' => $this->operator->id,
        ]);

        $results = $this->harness->runParallel([
            ['task' => 'cash_expense', 'params' => ['session_id' => $session->id, 'amount' => 100000, 'user_id' => $this->operator->id]],
            ['task' => 'close_session', 'params' => ['session_id' => $session->id, 'physical_closing_balance' => 400000, 'user_id' => $this->operator->id]],
        ]);

        $session->refresh();

        // Penutupan tidak boleh gagal karena race dengan transaksi kas.
        $this->assertSame(CashierSession::STATUS_CLOSED, $session->status);

        $posted = $session->transactions()->where('status', CashTransaction::STATUS_POSTED)->get();

        if ($posted->isEmpty()) {
            // Transaksi ditolak karena sesi sudah ditutup lebih dulu:
            // saldo sistem = saldo awal 500.000 (selisih fisik tercatat -100.000).
            $this->assertSame(500000.0, (float) $session->system_closing_balance);
            $this->assertSame(-100000.0, (float) $session->difference);
        } else {
            // Transaksi sah: snapshot penutupan wajib memasukkannya
            // (500.000 - 100.000 = 400.000, selisih fisik 0).
            $this->assertSame(400000.0, (float) $session->system_closing_balance);
            $this->assertSame(0.0, (float) $session->difference);
        }
    }

    /**
     * Kegagalan di tengah proses: error dari callback service-level membatalkan
     * seluruh perubahan (tidak ada saldo, transaksi, kas, atau audit parsial).
     */
    public function test_mid_transaction_failure_leaves_no_partial_effects(): void
    {
        $account = SavingsAccount::factory()->create(['balance' => 100000]);

        $before = [
            'balance' => (float) $account->balance,
            'transactions' => DB::table('savings_transactions')->count(),
            'cash' => DB::table('cash_transactions')->count(),
            'audit' => DB::table('audit_logs')->count(),
        ];

        try {
            DB::transaction(function () use ($account) {
                app(SavingsTransactionService::class)->deposit($account, [
                    'amount' => 50000,
                    'transaction_date' => now()->format('Y-m-d'),
                    'processed_by' => $this->operator->id,
                ]);

                throw new \RuntimeException('Simulasi kegagalan di tengah proses.');
            });
        } catch (\RuntimeException) {
            // Diharapkan: seluruh transaksi dibatalkan.
        }

        $account->refresh();
        $this->assertSame($before['balance'], (float) $account->balance);
        $this->assertSame($before['transactions'], DB::table('savings_transactions')->count());
        $this->assertSame($before['cash'], DB::table('cash_transactions')->count());
        $this->assertSame($before['audit'], DB::table('audit_logs')->count());
    }

    /**
     * Kegagalan audit trail yang diwajibkan: membuat audit log gagal (DB error
     * pada kolom wajib) dan memastikan transaksi simpanan TIDAK tersimpan
     * (audit adalah bagian wajib dari transaksi sensitif).
     */
    public function test_mandatory_audit_failure_rolls_back_source_transaction(): void
    {
        $account = SavingsAccount::factory()->create(['balance' => 0]);

        // Paksa kegagalan INSERT audit_logs melalui event database: kolom action
        // diset null sehingga constraint NOT NULL melanggar tepat saat AuditLogger
        // menulis log di dalam transaksi simpanan.
        DB::statement('CREATE OR REPLACE FUNCTION tests_block_audit() RETURNS trigger AS $$
            BEGIN NEW.action := NULL; RETURN NEW; END;
            $$ LANGUAGE plpgsql');
        DB::statement('CREATE TRIGGER tests_block_audit_trigger BEFORE INSERT ON audit_logs
            FOR EACH ROW EXECUTE FUNCTION tests_block_audit()');

        try {
            app(SavingsTransactionService::class)->deposit($account, [
                'amount' => 50000,
                'transaction_date' => now()->format('Y-m-d'),
                'processed_by' => $this->operator->id,
            ]);
            $this->fail('Transaksi seharusnya gagal karena audit trail wajib tidak dapat dicatat.');
        } catch (\Throwable) {
            // Diharapkan.
        }

        $account->refresh();
        $this->assertSame(0.0, (float) $account->balance, 'Saldo tidak boleh berubah saat audit wajib gagal.');
        $this->assertSame(0, DB::table('savings_transactions')->count());
        $this->assertSame(0, DB::table('cash_transactions')->count());
    }

    /**
     * Request transaksi berulang dengan kunci idempotensi sama secara berurutan
     * tetap satu transaksi (regresi dari test race paralel di atas).
     */
    public function test_repeated_requests_with_same_key_stay_single(): void
    {
        $account = SavingsAccount::factory()->create(['balance' => 0]);
        $key = 'repeat-key-'.uniqid();

        foreach ([1, 2, 3] as $attempt) {
            app(SavingsTransactionService::class)->deposit($account, [
                'amount' => 25000,
                'transaction_date' => now()->format('Y-m-d'),
                'processed_by' => $this->operator->id,
                'idempotency_key' => $key,
            ]);
        }

        $this->assertSame(1, DB::table('savings_transactions')->where('idempotency_key', $key)->count());
        $this->assertSame(25000.0, (float) $account->fresh()->balance);
    }

    /**
     * Transaksi dengan status tidak valid: pencairan pada pinjaman PENDING dan
     * pembayaran pada pinjaman REJECTED ditolak tanpa efek samping.
     */
    public function test_invalid_status_transitions_are_rejected(): void
    {
        $pendingLoan = Loan::factory()->create(['status' => 'PENDING']);

        $results = $this->harness->runParallel([
            ['task' => 'loan_disburse', 'params' => ['loan_id' => $pendingLoan->id, 'user_id' => $this->operator->id, 'method' => 'TRANSFER']],
        ]);

        $this->assertFalse($results->first()->ok);
        $this->assertSame(0, DB::table('loan_disbursements')->where('loan_id', $pendingLoan->id)->count());
        $this->assertSame('PENDING', $pendingLoan->fresh()->status);

        $rejectedLoan = Loan::factory()->rejected()->create();
        $paymentResults = $this->harness->runParallel([
            ['task' => 'installment_pay', 'params' => ['loan_id' => $rejectedLoan->id, 'amount' => 100000, 'user_id' => $this->operator->id]],
        ]);

        $this->assertFalse($paymentResults->first()->ok);
        $this->assertSame(0, DB::table('installment_payments')->where('loan_id', $rejectedLoan->id)->count());
    }

    /**
     * Sesi kasir yang sudah ditutup menolak transaksi baru melalui service.
     */
    public function test_closed_session_rejects_new_cash_transaction_from_service(): void
    {
        $session = CashierSession::factory()->closed()->create([
            'opened_by' => $this->operator->id,
        ]);

        $results = $this->harness->runParallel([
            ['task' => 'cash_expense', 'params' => ['session_id' => $session->id, 'amount' => 50000, 'user_id' => $this->operator->id]],
        ]);

        $this->assertFalse($results->first()->ok);
        $this->assertSame(0, $session->transactions()->count());
    }

    // =====================================================================
    // Ticket 16 — skenario konkurensi & idempotensi tambahan (bagian 8).
    // =====================================================================

    /**
     * Skenario 8.2: dua pembayaran angsuran PARALEL dengan idempotency key
     * yang sama menghasilkan tepat satu pembayaran dan satu dampak kas.
     */
    public function test_parallel_installment_payments_with_same_idempotency_key_create_single_payment(): void
    {
        $loan = Loan::factory()->approved()->create(['amount' => 1_200_000, 'tenor_months' => 4]);
        app(LoanService::class)->disburse($loan, [
            'disbursement_date' => now()->format('Y-m-d'),
            'first_due_date' => now()->addMonth()->format('Y-m-d'),
            'method' => 'TRANSFER',
        ], $this->operator->id);

        $key = 'pay-race-'.uniqid();

        $results = $this->harness->runParallel([
            ['task' => 'installment_pay', 'params' => ['loan_id' => $loan->id, 'amount' => 300_000, 'user_id' => $this->operator->id, 'idempotency_key' => $key]],
            ['task' => 'installment_pay', 'params' => ['loan_id' => $loan->id, 'amount' => 300_000, 'user_id' => $this->operator->id, 'idempotency_key' => $key]],
        ]);

        $this->assertSame(1, DB::table('installment_payments')->where('idempotency_key', $key)->count());

        $loan->refresh();
        $this->assertSame(300_000.0, (float) $loan->payments()->where('status', 'COMPLETED')->sum('amount'));
        $this->assertSame(300_000.0, round((float) $loan->schedules()->sum('paid_amount'), 2));
    }

    /**
     * Skenario 8.3: dua pembayaran BERBEDA yang berjalan PARALEL bersama-sama
     * melunasi pinjaman. Alokasi tidak boleh menimpa satu sama lain dan
     * kewajiban akhir harus nol (tidak over-allocasi maupun sisa).
     */
    public function test_parallel_payments_settling_the_same_loan_stay_consistent(): void
    {
        $loan = Loan::factory()->approved()->create(['amount' => 1_200_000, 'tenor_months' => 4]);
        app(LoanService::class)->disburse($loan, [
            'disbursement_date' => now()->format('Y-m-d'),
            'first_due_date' => now()->addMonth()->format('Y-m-d'),
            'method' => 'TRANSFER',
            'interest_method' => 'flat',
            'interest_rate_annual' => 12.0,
        ], $this->operator->id);

        $loan->refresh();
        $totalOwed = $loan->total_owed; // pokok 1.200.000 + bunga flat 12% = 1.488.000

        $firstAmount = round($totalOwed / 2, 2);
        $secondAmount = round($totalOwed - $firstAmount, 2);

        $results = $this->harness->runParallel([
            ['task' => 'installment_pay', 'params' => ['loan_id' => $loan->id, 'amount' => $firstAmount, 'user_id' => $this->operator->id]],
            ['task' => 'installment_pay', 'params' => ['loan_id' => $loan->id, 'amount' => $secondAmount, 'user_id' => $this->operator->id]],
        ]);

        $results->assertAllOk();

        $loan->refresh();

        // Seluruh kewajiban teralokasi, tidak ada sisa maupun kelebihan.
        $this->assertSame(0.0, $loan->total_owed);
        $this->assertSame('PAID_OFF', $loan->status);
        $this->assertSame($totalOwed, round((float) $loan->payments()->where('status', 'COMPLETED')->sum('amount'), 2));
        $this->assertSame($totalOwed, round((float) $loan->schedules()->sum('paid_amount')
            + (float) $loan->schedules()->sum('interest_paid'), 2));
    }

    /**
     * Skenario 8.4: dua reversal PARALEL terhadap transaksi simpanan yang
     * sama — hanya satu yang boleh berhasil; saldo akhir = posisi sebelum
     * transaksi asal, dan tepat satu baris reversal yang terhubung.
     */
    public function test_parallel_reversals_of_same_transaction_create_single_reversal(): void
    {
        $account = SavingsAccount::factory()->create(['balance' => 0]);
        $service = app(SavingsTransactionService::class);

        $original = $service->deposit($account, [
            'amount' => 150_000,
            'transaction_date' => now()->format('Y-m-d'),
            'processed_by' => $this->operator->id,
        ]);

        $results = $this->harness->runParallel([
            ['task' => 'savings_reverse', 'params' => ['transaction_id' => $original->id, 'user_id' => $this->operator->id]],
            ['task' => 'savings_reverse', 'params' => ['transaction_id' => $original->id, 'user_id' => $this->operator->id]],
        ]);

        $successCount = $results->whereOk()->count();

        // Tepat satu reversal berhasil; yang lain ditolak validasi.
        $this->assertSame(1, $successCount, 'Hanya satu reversal konkuren yang boleh berhasil.');
        $this->assertSame(1, DB::table('savings_transactions')->where('reversal_of_id', $original->id)->count());

        $account->refresh();
        $this->assertSame(0.0, (float) $account->balance, 'Saldo akhir harus kembali ke posisi sebelum transaksi asal.');

        $original->refresh();
        $this->assertSame('completed', $original->status);
        $this->assertSame(150_000.0, (float) $original->amount);
    }

    /**
     * Skenario 8.5: dua akrual denda PARALEL untuk periode yang sama tidak
     * menggandakan denda — kewajiban denda akhir = satu kali akumulasi penuh.
     */
    public function test_parallel_penalty_accrual_for_same_period_does_not_double_count(): void
    {
        $loan = Loan::factory()->approved()->create(['amount' => 4_000_000, 'tenor_months' => 4]);
        app(LoanService::class)->disburse($loan, [
            'disbursement_date' => now()->format('Y-m-d'),
            'first_due_date' => now()->addMonth()->format('Y-m-d'),
            'method' => 'TRANSFER',
        ], $this->operator->id);

        $schedule = $loan->schedules()->first();
        $schedule->update(['status' => 'OVERDUE', 'due_date' => now()->subDays(30)->format('Y-m-d')]);

        // Konfigurasi denda dikirim via payload worker (proses terpisah).
        $penaltyConfig = [
            'penalty_method' => 'per_day',
            'amount_per_day' => 1_000,
            'grace_days' => 0,
        ];

        $results = $this->harness->runParallel([
            ['task' => 'penalty_accrue', 'params' => ['schedule_id' => $schedule->id] + $penaltyConfig],
            ['task' => 'penalty_accrue', 'params' => ['schedule_id' => $schedule->id] + $penaltyConfig],
        ]);

        $results->assertAllOk();

        $schedule->refresh();

        // Carbon 3 mengembalikan selisih bertanda; ambil nilai absolut.
        $daysLate = abs(now()->startOfDay()->diffInDays($schedule->due_date->startOfDay()));

        // Denda akhir tepat satu kali akumulasi, bukan dua kali (proteksi
        // double-count via penalty_accrued_through harus berlaku konkuren).
        // Perhitungan hari inklusif: jatuh tempo s.d. hari referensi.
        $this->assertSame(round(1_000.0 * ($daysLate + 1), 2), round((float) $schedule->penalty_accrued, 2));
        $this->assertSame(now()->format('Y-m-d'), optional($schedule->penalty_accrued_through)->format('Y-m-d'));
    }

    /**
     * Skenario 8.7: dua transaksi kas PARALEL dengan idempotency key sama —
     * tepat satu transaksi kas tercipta tanpa dampak ganda pada saldo sesi.
     */
    public function test_parallel_cash_transactions_with_same_idempotency_key_create_single_transaction(): void
    {
        $session = CashierSession::factory()->withOpeningBalance(500_000)->create([
            'opened_by' => $this->operator->id,
        ]);

        $key = 'cash-race-'.uniqid();

        $results = $this->harness->runParallel([
            ['task' => 'cash_expense', 'params' => ['session_id' => $session->id, 'amount' => 100_000, 'user_id' => $this->operator->id, 'idempotency_key' => $key]],
            ['task' => 'cash_expense', 'params' => ['session_id' => $session->id, 'amount' => 100_000, 'user_id' => $this->operator->id, 'idempotency_key' => $key]],
        ]);

        $this->assertSame(1, DB::table('cash_transactions')->where('idempotency_key', $key)->count());

        $session->refresh();
        $posted = (float) $session->transactions()->where('status', CashTransaction::STATUS_POSTED)->sum('amount');
        $this->assertSame(100_000.0, $posted);
    }

    /**
     * T102 — Dua pembuatan Nasabah SHM PARALEL untuk dua anggota berbeda:
     * sequence domain Nasabah SHM menghasilkan kode UNIK berurutan
     * (`00001-ANG` dan `00002-ANG`) tanpa duplikat — tanpa membaca MAX+1
     * telanjang dan tanpa memakai kode anggota.
     */
    public function test_parallel_customer_creations_get_unique_sequential_codes(): void
    {
        $members = Member::factory()->count(2)->create(['status' => 'active']);

        $results = $this->harness->runParallel([
            ['task' => 'shm_customer_create', 'params' => ['member_id' => $members[0]->id, 'name' => 'Nasabah Paralel A']],
            ['task' => 'shm_customer_create', 'params' => ['member_id' => $members[1]->id, 'name' => 'Nasabah Paralel B']],
        ]);

        $results->assertAllOk();

        $codes = DB::table('shm_customers')->pluck('customer_number')->all();

        $this->assertCount(2, $codes, 'Kedua pembuatan paralel harus berhasil.');
        $this->assertCount(2, array_unique($codes), 'Pembuatan paralel tidak boleh menghasilkan kode duplikat.');

        sort($codes);
        $this->assertSame(['00001-ANG', '00002-ANG'], $codes);
    }

    /**
     * T102-FIX — Dua pembuatan anggota PARALEL: sequence domain anggota
     * (`members_code_seq`) menghasilkan kode `NNNNN-ANG` UNIK berurutan
     * (`00001-ANG` dan `00002-ANG`) tanpa duplikat — tanpa MAX+1 telanjang.
     */
    public function test_parallel_member_creations_get_unique_sequential_codes(): void
    {
        $results = $this->harness->runParallel([
            ['task' => 'member_create', 'params' => ['full_name' => 'Anggota Paralel A']],
            ['task' => 'member_create', 'params' => ['full_name' => 'Anggota Paralel B']],
        ]);

        $results->assertAllOk();

        $codes = DB::table('members')->pluck('member_number')->all();

        $this->assertCount(2, $codes, 'Kedua pembuatan anggota paralel harus berhasil.');
        $this->assertCount(2, array_unique($codes), 'Pembuatan anggota paralel tidak boleh menghasilkan kode duplikat.');

        sort($codes);
        $this->assertSame(['00001-ANG', '00002-ANG'], $codes);
    }

    private function postgresAvailable(): bool
    {
        try {
            config(['database.connections.pgsql_test_check' => [
                'driver' => 'pgsql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '5432'),
                'database' => 'postgres',
                'username' => env('DB_USERNAME', 'postgres'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => 'utf8',
            ]]);
            DB::connection('pgsql_test_check')->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
