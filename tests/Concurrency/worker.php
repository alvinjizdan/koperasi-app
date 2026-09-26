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
use App\Models\Loan;
use App\Models\SavingsAccount;
use App\Services\CashierService;
use App\Services\InstallmentPaymentService;
use App\Services\LoanService;
use App\Services\SavingsTransactionService;
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
