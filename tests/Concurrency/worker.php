<?php

/**
 * Worker pengujian konkurensi (proses PHP terpisah, bukan thread).
 *
 * Dipanggil oleh ConcurrencyAndFailureTest melalui proc_open dengan payload
 * base64 pada argv[1]. Setiap worker mem-boot aplikasi secara penuh dan
 * terhubung ke database PostgreSQL pengujian dengan koneksi sendiri sehingga
 * operasi benar-benar berjalan paralel, bukan berurutan pada satu koneksi.
 */

use App\Models\CashierSession;
use App\Models\InstallmentSchedule;
use App\Models\Loan;
use App\Models\Member;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanSettlement;
use App\Services\CashierService;
use App\Services\InstallmentPaymentService;
use App\Services\LoanService;
use App\Services\Penalty\PenaltyCalculator;
use App\Services\SavingsTransactionService;
use App\Services\Shm\ShmLoanDisbursementService;
use App\Services\Shm\ShmLoanSettlementService;
use App\Services\Shm\ShmRegularPaymentService;
use App\Services\Shm\ShmSettlementCorrectionService;
use App\Services\ShmCustomerService;
use App\Support\DbTransaction;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

const RESULT_MARKER = '###RESULT###';

function emit(array $payload): void
{
    echo RESULT_MARKER.json_encode($payload);
}

$raw = base64_decode($argv[1] ?? '', true);

if ($raw === false) {
    emit(['ok' => false, 'kind' => 'error', 'message' => 'Payload worker tidak valid.']);
    exit(0);
}

$payload = json_decode($raw, true);
$task = $payload['task'] ?? '';
$params = $payload['params'] ?? [];

$app = require __DIR__.'/../../bootstrap/app.php';

$app->make(ConsoleKernel::class)->bootstrap();

// Arahkan koneksi worker ke database pengujian PostgreSQL (bukan sqlite :memory:).
config([
    'database.default' => 'pgsql',
    'database.connections.pgsql.database' => $payload['database'],
]);
DB::purge('pgsql');
DB::reconnect('pgsql');

try {
    $result = match ($task) {
        'savings_deposit' => (function () use ($params) {
            $transaction = app(SavingsTransactionService::class)->deposit(
                SavingsAccount::findOrFail($params['account_id']),
                [
                    'amount' => $params['amount'],
                    'transaction_date' => now()->format('Y-m-d'),
                    'description' => 'Uji konkurensi setoran',
                    'processed_by' => $params['user_id'],
                    'idempotency_key' => $params['idempotency_key'] ?? null,
                ],
            );

            return ['transaction_id' => $transaction->id];
        })(),

        'savings_withdraw' => (function () use ($params) {
            $transaction = app(SavingsTransactionService::class)->withdraw(
                SavingsAccount::findOrFail($params['account_id']),
                [
                    'amount' => $params['amount'],
                    'transaction_date' => now()->format('Y-m-d'),
                    'description' => 'Uji konkurensi penarikan',
                    'processed_by' => $params['user_id'],
                    'idempotency_key' => $params['idempotency_key'] ?? null,
                ],
            );

            return ['transaction_id' => $transaction->id];
        })(),

        'cash_expense' => (function () use ($params) {
            $transaction = app(CashierService::class)->recordExpense(
                CashierSession::findOrFail($params['session_id']),
                [
                    'amount' => $params['amount'],
                    'category' => 'Biaya Operasional',
                    'transaction_date' => now()->format('Y-m-d'),
                    'description' => 'Uji konkurensi pengeluaran kas',
                    'idempotency_key' => $params['idempotency_key'] ?? null,
                ],
                (int) $params['user_id'],
            );

            return ['transaction_id' => $transaction->id];
        })(),

        'loan_disburse' => (function () use ($params) {
            $disbursement = app(LoanService::class)->disburse(
                Loan::findOrFail($params['loan_id']),
                [
                    'disbursement_date' => now()->format('Y-m-d'),
                    'first_due_date' => now()->addMonth()->format('Y-m-d'),
                    'method' => $params['method'] ?? 'TRANSFER',
                ],
                (int) $params['user_id'],
            );

            return ['disbursement_id' => $disbursement->id];
        })(),

        'installment_pay' => (function () use ($params) {
            $payment = app(InstallmentPaymentService::class)->pay(
                Loan::findOrFail($params['loan_id']),
                [
                    'amount' => $params['amount'],
                    'payment_date' => now()->format('Y-m-d'),
                    'idempotency_key' => $params['idempotency_key'] ?? null,
                ],
                (int) $params['user_id'],
            );

            return ['payment_id' => $payment->id];
        })(),

        'savings_reverse' => (function () use ($params) {
            $reversal = app(SavingsTransactionService::class)->reverse(
                SavingsTransaction::findOrFail($params['transaction_id']),
                (int) $params['user_id'],
                'Uji konkurensi reversal',
            );

            return ['reversal_id' => $reversal->id];
        })(),

        'penalty_accrue' => (function () use ($params) {
            // Konfigurasi denda dikirim via payload karena worker adalah proses
            // terpisah dengan config default masing-masing.
            config([
                'business-rules.penalty.method' => $params['penalty_method'] ?? 'none',
                'business-rules.penalty.amount_per_day' => $params['amount_per_day'] ?? 0,
                'business-rules.penalty.grace_days' => $params['grace_days'] ?? 0,
            ]);

            // Akrual denda wajib lock baris jadwal (SELECT ... FOR UPDATE)
            // sebelum baca-hitung-tulis, agar dua proses untuk periode sama
            // tidak menggandakan denda. Ini pola yang wajib dipakai integrasi
            // produksi saat denda diaktifkan (D-02).
            return DbTransaction::run(function () use ($params) {
                $schedule = InstallmentSchedule::lockForUpdate()->findOrFail($params['schedule_id']);
                $result = app(PenaltyCalculator::class)->accrueForSchedule($schedule);

                $schedule->penalty_accrued = (float) $schedule->penalty_accrued + $result['accrued'];
                $schedule->penalty_accrued_through = $result['through'];
                $schedule->save();

                return ['accrued' => $result['accrued']];
            });
        })(),

        'close_session' => (function () use ($params) {
            $closed = app(CashierService::class)->closeSession(
                CashierSession::findOrFail($params['session_id']),
                [
                    'physical_closing_balance' => $params['physical_closing_balance'],
                    'closing_notes' => 'Uji konkurensi penutupan sesi.',
                ],
                (int) $params['user_id'],
            );

            return ['difference' => (float) $closed->difference, 'session_id' => $closed->id];
        })(),

        'shm_customer_create' => (function () use ($params) {
            // T102: pembuatan nasabah dari dua proses PARALEL — sequence
            // domain Nasabah SHM harus menghasilkan kode unik tanpa
            // duplikat (nextval + unique constraint, bukan MAX+1 telanjang).
            $customer = app(ShmCustomerService::class)->create([
                'member_id' => (int) $params['member_id'],
                'name' => $params['name'] ?? 'Nasabah Paralel',
            ]);

            return [
                'customer_id' => $customer->id,
                'customer_number' => $customer->customer_number,
            ];
        })(),

        'shm_disburse' => (function () use ($params) {
            // T002C: pencairan SHM dari dua proses PARALEL untuk pengajuan
            // yang sama — lockForUpdate baris pengajuan + constraint UNIQUE
            // shm_loans.shm_loan_application_id harus menjamin tepat SATU
            // pinjaman & SATU event finansial (idempotency + konkurensi).
            $loan = app(ShmLoanDisbursementService::class)->disburse(
                ShmLoanApplication::findOrFail($params['application_id']),
                (int) $params['user_id'],
            );

            return ['loan_id' => $loan->id, 'loan_number' => $loan->loan_number];
        })(),

        'shm_payment' => (function () use ($params) {
            // T006E-FV: Pembayaran angsuran SHM dari dua proses PARALEL
            $result = app(ShmRegularPaymentService::class)->pay(
                ShmLoan::findOrFail($params['loan_id']),
                [
                    'payment_date' => $params['payment_date'] ?? now()->toDateString(),
                    'amount' => $params['amount'],
                    'idempotency_key' => $params['idempotency_key'] ?? null,
                ],
                (int) $params['user_id'],
            );

            return [
                'payment_id' => $result['payment']->id,
                'payment_reference' => $result['payment']->payment_reference,
            ];
        })(),

        'shm_settlement_complete' => (function () use ($params) {
            // T006E-FV: Penyelesaian settlement SHM dari dua proses PARALEL
            $settlement = app(ShmLoanSettlementService::class)->complete(
                ShmLoan::findOrFail($params['loan_id']),
                (int) $params['user_id'],
            );

            return [
                'settlement_id' => $settlement->id,
                'settlement_reference' => $settlement->settlement_reference,
            ];
        })(),

        'shm_settlement_correct' => (function () use ($params) {
            // T006E-FV: Koreksi settlement SHM dari dua proses PARALEL
            $correction = app(ShmSettlementCorrectionService::class)->correct(
                ShmLoan::findOrFail($params['loan_id']),
                ShmLoanSettlement::findOrFail($params['settlement_id']),
                $params['reason'] ?? 'Koreksi konkurensi paralel',
                (int) $params['user_id'],
            );

            return [
                'correction_id' => $correction->id,
            ];
        })(),

        'member_create' => (function () use ($params) {
            // T102-FIX: pembuatan anggota dari dua proses PARALEL — sequence
            // domain anggota (members_code_seq) harus menghasilkan kode
            // NNNNN-ANG unik tanpa duplikat, lewat jalur produksi yang sama
            // (Member::createWithGeneratedNumber).
            $member = Member::createWithGeneratedNumber([
                'full_name' => $params['full_name'] ?? 'Anggota Paralel',
                'joined_at' => now()->toDateString(),
                'status' => 'active',
            ]);

            return [
                'member_id' => $member->id,
                'member_number' => $member->member_number,
            ];
        })(),

        default => throw new InvalidArgumentException("Tugas worker tidak dikenal: {$task}"),
    };

    emit(['ok' => true] + $result);
} catch (ValidationException $e) {
    emit(['ok' => false, 'kind' => 'validation', 'errors' => $e->errors()]);
} catch (QueryException $e) {
    emit(['ok' => false, 'kind' => 'query', 'sqlstate' => $e->getCode(), 'message' => mb_substr($e->getMessage(), 0, 300)]);
} catch (Throwable $e) {
    emit(['ok' => false, 'kind' => 'error', 'message' => mb_substr($e->getMessage(), 0, 300)]);
}

exit(0);
