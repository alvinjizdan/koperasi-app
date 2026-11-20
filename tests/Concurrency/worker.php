<?php

/**
 * Worker pengujian konkurensi (proses PHP terpisah, bukan thread).
 *
 * Dipanggil oleh ConcurrencyAndFailureTest melalui proc_open dengan payload
 * base64 pada argv[1]. Setiap worker mem-boot aplikasi secara penuh dan
 * terhubung ke database PostgreSQL pengujian dengan koneksi sendiri sehingga
 * operasi benar-benar berjalan paralel, bukan berurutan pada satu koneksi.
 */

use App\Models\AuditLog;
use App\Models\CashierSession;
use App\Models\InstallmentSchedule;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\Member;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Models\ShmApplication;
use App\Models\ShmCashTransaction;
use App\Models\ShmLoan;
use App\Models\ShmLoanApplication;
use App\Models\ShmLoanSettlement;
use App\Models\ShmMemorandum;
use App\Models\ShmRefundAccountingLink;
use App\Models\ShmSettlementRefund;
use App\Models\User;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\ShmCashFlowService;
use App\Services\Accounting\ShmJournalImportService;
use App\Services\CashierService;
use App\Services\InstallmentPaymentService;
use App\Services\LoanService;
use App\Services\Penalty\PenaltyCalculator;
use App\Services\SavingsTransactionService;
use App\Services\Shm\ShmCreditCheckExceptionService;
use App\Services\Shm\ShmLoanDisbursementService;
use App\Services\Shm\ShmLoanSettlementService;
use App\Services\Shm\ShmMemorandumApprovalService;
use App\Services\Shm\ShmRegularPaymentService;
use App\Services\Shm\ShmSettlementCorrectionService;
use App\Services\Shm\ShmSettlementRefundService;
use App\Services\ShmCustomerService;
use App\Support\DbTransaction;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
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
            $payload = [
                'name' => $params['name'] ?? 'Nasabah Paralel',
            ];
            if (isset($params['member_id'])) {
                $payload['member_id'] = (int) $params['member_id'];
            }
            $customer = app(ShmCustomerService::class)->create($payload);

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

        'shm_journal_import' => (function () use ($params) {
            $filePath = $params['file_path'];
            $file = new UploadedFile($filePath, basename($filePath), null, null, true);
            $batch = app(ShmJournalImportService::class)->import($file, (int) $params['user_id']);

            return [
                'batch_id' => $batch->id,
            ];
        })(),

        'shm_journal_post' => (function () use ($params) {
            $entry = JournalEntry::findOrFail($params['entry_id']);
            $posted = app(JournalService::class)->post($entry, (int) $params['user_id']);

            return [
                'entry_id' => $posted->id,
            ];
        })(),

        'shm_journal_reverse' => (function () use ($params) {
            $entry = JournalEntry::findOrFail($params['entry_id']);
            $reversed = app(JournalService::class)->reverse($entry, (int) $params['user_id'], $params['reason'] ?? 'Reversal worker');

            return [
                'reversal_id' => $reversed->id,
            ];
        })(),

        'shm_refund_request' => (function () use ($params) {
            $settlement = ShmLoanSettlement::findOrFail($params['settlement_id']);
            $refund = app(ShmSettlementRefundService::class)->request(
                $settlement,
                [
                    'amount' => $params['amount'],
                    'payout_method' => $params['payout_method'] ?? 'cash',
                ],
                (int) $params['user_id']
            );

            return [
                'refund_id' => $refund->id,
            ];
        })(),

        'shm_refund_approve' => (function () use ($params) {
            $refund = ShmSettlementRefund::findOrFail($params['refund_id']);
            $approved = app(ShmSettlementRefundService::class)->approve($refund, (int) $params['user_id']);

            return [
                'refund_id' => $approved->id,
            ];
        })(),

        'shm_refund_payout' => (function () use ($params) {
            $refund = ShmSettlementRefund::findOrFail($params['refund_id']);
            $paid = app(ShmSettlementRefundService::class)->executePayout(
                $refund,
                [
                    'payout_reference' => $params['payout_reference'] ?? 'PAYOUT-AUTO',
                    'payout_evidence_path' => $params['payout_evidence_path'] ?? 'shm/refund_evidence/pay.pdf',
                    'recipient_name' => $params['recipient_name'] ?? 'Nasabah Payout',
                ],
                (int) $params['user_id']
            );

            return [
                'refund_id' => $paid->id,
            ];
        })(),

        'shm_refund_recovery_confirm' => (function () use ($params) {
            $refund = ShmSettlementRefund::findOrFail($params['refund_id']);
            $recovered = app(ShmSettlementRefundService::class)->confirmRecovery(
                $refund,
                [
                    'amount' => $params['amount'],
                    'recovery_reference' => $params['recovery_reference'] ?? 'REC-AUTO-'.uniqid(),
                    'recovery_method' => $params['recovery_method'] ?? 'cash',
                    'recovery_evidence_path' => $params['recovery_evidence_path'] ?? 'shm/refund_recovery/evidence.pdf',
                ],
                (int) $params['user_id']
            );

            return [
                'refund_id' => $recovered->id,
            ];
        })(),

        'shm_refund_accounting_link' => (function () use ($params) {
            $refund = ShmSettlementRefund::findOrFail($params['refund_id']);
            $link = app(ShmSettlementRefundService::class)->linkAccountingRecognition(
                $refund,
                (int) $params['journal_entry_id'],
                $params['channel'],
                (int) $params['user_id']
            );

            return [
                'link_id' => $link->id,
            ];
        })(),

        'shm_cash_outflow_post' => (function () use ($params) {
            $cashTx = ShmCashTransaction::findOrFail($params['transaction_id']);
            $posted = app(ShmCashFlowService::class)->post($cashTx, (int) $params['user_id']);

            return [
                'transaction_id' => $posted->id,
            ];
        })(),

        'shm_manual_journal_post' => (function () use ($params) {
            $entry = JournalEntry::findOrFail($params['journal_entry_id']);
            $posted = app(JournalService::class)->post($entry, (int) $params['user_id']);

            return [
                'entry_id' => $posted->id,
            ];
        })(),

        'shm_refund_reversal_reverse' => (function () use ($params) {
            $entry = JournalEntry::findOrFail($params['journal_entry_id']);
            $reversal = app(JournalService::class)->reverse($entry, (int) $params['user_id'], $params['reason'] ?? 'Reversal worker');

            return [
                'entry_id' => $reversal->id,
            ];
        })(),

        'shm_draft_cancellation' => (function () use ($params) {
            return DbTransaction::run(function () use ($params) {
                $locked = ShmCashTransaction::query()->lockForUpdate()->findOrFail($params['transaction_id']);
                if ($locked->status !== ShmCashTransaction::STATUS_DRAFT) {
                    throw ValidationException::withMessages([
                        'status' => ['Hanya transaksi DRAFT yang dapat dibatalkan.'],
                    ]);
                }

                $txId = $locked->id;
                AuditLog::create([
                    'action' => 'shm_cash_transaction.draft_deleted',
                    'object_type' => 'ShmCashTransaction',
                    'object_id' => $txId,
                    'actor_id' => (int) $params['user_id'],
                    'description' => $params['reason'] ?? 'Pembatalan draft kas',
                ]);

                $locked->delete();

                return ['cancelled' => true];
            });
        })(),

        'shm_simulated_deadlock' => (function () use ($params) {
            return DbTransaction::run(function () use ($params) {
                $firstId = $params['order'] === 'A' ? $params['id1'] : $params['id2'];
                $secondId = $params['order'] === 'A' ? $params['id2'] : $params['id1'];

                $first = ShmSettlementRefund::query()->lockForUpdate()->find($firstId);
                usleep(50_000);
                $second = ShmSettlementRefund::query()->lockForUpdate()->find($secondId);

                return [
                    'first' => $first?->id,
                    'second' => $second?->id,
                ];
            });
        })(),

        'shm_simulated_serialization' => (function () use ($params) {
            return DbTransaction::run(function () use ($params) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');

                $settlement = ShmLoanSettlement::findOrFail($params['settlement_id']);
                $sum = ShmSettlementRefund::where('shm_loan_settlement_id', $settlement->id)->sum('amount');

                usleep(30_000);

                $refund = ShmSettlementRefund::findOrFail($params['refund_id']);
                $refund->reason = 'Updated in serializable: '.$sum;
                $refund->save();

                return [
                    'refund_id' => $refund->id,
                    'sum' => $sum,
                ];
            });
        })(),

        'shm_nested_deadlock' => (function () use ($params) {
            return DB::transaction(function () use ($params) {
                return DbTransaction::run(function () use ($params) {
                    $firstId = $params['order'] === 'A' ? $params['id1'] : $params['id2'];
                    $secondId = $params['order'] === 'A' ? $params['id2'] : $params['id1'];

                    $first = ShmSettlementRefund::query()->lockForUpdate()->find($firstId);
                    usleep(50_000);
                    $second = ShmSettlementRefund::query()->lockForUpdate()->find($secondId);

                    return [
                        'first' => $first?->id,
                        'second' => $second?->id,
                    ];
                });
            });
        })(),

        'shm_nested_serialization' => (function () use ($params) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');

            return DB::transaction(function () use ($params) {
                return DbTransaction::run(function () use ($params) {
                    $settlement = ShmLoanSettlement::findOrFail($params['settlement_id']);
                    $sum = ShmSettlementRefund::where('shm_loan_settlement_id', $settlement->id)->sum('amount');

                    usleep(30_000);

                    $refund = ShmSettlementRefund::findOrFail($params['refund_id']);
                    $refund->reason = 'Updated nested serializable: '.$sum;
                    $refund->save();

                    return [
                        'refund_id' => $refund->id,
                        'sum' => $sum,
                    ];
                });
            });
        })(),

        'shm_outer_nested_deadlock' => (function () use ($params) {
            $outerAttempts = 0;
            $innerInvocations = 0;
            $caughtSqlStates = [];
            $retrySqlStates = [];

            $result = DbTransaction::run(function () use ($params, &$outerAttempts, &$innerInvocations, &$caughtSqlStates) {
                $outerAttempts++;

                AuditLog::create([
                    'action' => 'test.outer_retry.probe',
                    'object_type' => 'test',
                    'object_label' => $params['label'],
                    'description' => "Attempt {$outerAttempts}",
                ]);

                return DbTransaction::run(function () use ($params, &$innerInvocations) {
                    $innerInvocations++;

                    $firstId = $params['order'] === 'A' ? $params['id1'] : $params['id2'];
                    $secondId = $params['order'] === 'A' ? $params['id2'] : $params['id1'];

                    $first = ShmSettlementRefund::query()->lockForUpdate()->find($firstId);
                    usleep(150_000);
                    $second = ShmSettlementRefund::query()->lockForUpdate()->find($secondId);

                    return [
                        'first' => $first?->id,
                        'second' => $second?->id,
                    ];
                });
            }, function (Throwable $e, int $attempt, string $sqlState) use (&$caughtSqlStates, &$retrySqlStates) {
                $caughtSqlStates[] = $sqlState;
                $retrySqlStates[] = [
                    'attempt' => $attempt,
                    'sqlstate' => $sqlState,
                ];
            });

            return [
                'outer_attempts' => $outerAttempts,
                'inner_invocations' => $innerInvocations,
                'caught_sqlstates' => $caughtSqlStates,
                'retry_sqlstates' => $retrySqlStates,
                'marker' => "outer-probe:{$params['label']}:attempt:{$outerAttempts}",
                'label' => $params['label'],
            ];
        })(),

        'shm_outer_nested_serialization' => (function () use ($params) {
            $outerAttempts = 0;
            $innerInvocations = 0;
            $caughtSqlStates = [];
            $retrySqlStates = [];

            $result = DbTransaction::run(function () use ($params, &$outerAttempts, &$innerInvocations) {
                $outerAttempts++;

                DB::statement('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');

                AuditLog::create([
                    'action' => 'test.outer_serialization.probe',
                    'object_type' => 'test',
                    'object_label' => $params['label'],
                    'description' => "Attempt {$outerAttempts}",
                ]);

                return DbTransaction::run(function () use ($params, &$innerInvocations, $outerAttempts) {
                    $innerInvocations++;

                    $settlement = ShmLoanSettlement::findOrFail($params['settlement_id']);
                    $count = ShmSettlementRefund::where('shm_loan_settlement_id', $settlement->id)->count();

                    usleep(40_000);

                    $target = ShmSettlementRefund::findOrFail($params['target_refund_id']);
                    $target->reason = "serial-probe:{$params['label']}:attempt:{$outerAttempts}";
                    $target->save();

                    return [
                        'count' => $count,
                        'refund_id' => $target->id,
                    ];
                });
            }, function (Throwable $e, int $attempt, string $sqlState) use (&$caughtSqlStates, &$retrySqlStates) {
                $caughtSqlStates[] = $sqlState;
                $retrySqlStates[] = [
                    'attempt' => $attempt,
                    'sqlstate' => $sqlState,
                ];
            });

            return [
                'outer_attempts' => $outerAttempts,
                'inner_invocations' => $innerInvocations,
                'caught_sqlstates' => $caughtSqlStates,
                'retry_sqlstates' => $retrySqlStates,
                'label' => $params['label'],
            ];
        })(),

        'shm_retry_exhaustion' => (function () use ($params) {
            $attempts = 0;
            $retrySqlStates = [];
            $exhausted = false;
            $observedSqlState = null;

            try {
                DbTransaction::run(function () use ($params, &$attempts) {
                    $attempts++;

                    AuditLog::create([
                        'action' => 'test.retry_exhaustion.probe',
                        'object_type' => 'test',
                        'object_label' => $params['label'],
                        'description' => "Attempt {$attempts}",
                    ]);

                    $driver = DB::connection()->getPdo();
                    $pdoException = new PDOException("Simulated {$params['sqlstate']}");
                    $pdoException->errorInfo = [$params['sqlstate'], 1, "Simulated {$params['sqlstate']} error"];

                    throw new QueryException(
                        'pgsql',
                        'SELECT 1',
                        [],
                        $pdoException
                    );
                }, function (Throwable $e, int $attempt, string $sqlState) use (&$retrySqlStates) {
                    $retrySqlStates[] = [
                        'attempt' => $attempt,
                        'sqlstate' => $sqlState,
                    ];
                });
            } catch (Throwable $e) {
                $exhausted = true;
                $observedSqlState = DbTransaction::extractSqlState($e);
            }

            return [
                'exhausted' => $exhausted,
                'attempts' => $attempts,
                'sqlstate' => $observedSqlState,
                'retry_sqlstates' => $retrySqlStates,
            ];
        })(),

        'shm_reversal_then_replacement' => (function () use ($params) {
            $journalService = app(JournalService::class);
            $cashFlowService = app(ShmCashFlowService::class);

            $original = JournalEntry::findOrFail($params['journal_entry_id']);
            $reversed = $journalService->reverse($original, (int) $params['user_id'], $params['reason']);
            $reversalCommittedAt = microtime(true);

            $linkAfterReversal = ShmRefundAccountingLink::where('journal_entry_id', $original->id)->first();

            $replacementTx = ShmCashTransaction::findOrFail($params['replacement_transaction_id']);
            $postedReplacement = $cashFlowService->post($replacementTx, (int) $params['user_id']);
            $replacementCommittedAt = microtime(true);

            return [
                'original_status' => $original->fresh()->status,
                'link_status_after_reversal' => $linkAfterReversal?->status,
                'replacement_journal_id' => $postedReplacement->journal_entry_id,
                'reversal_committed_at' => $reversalCommittedAt,
                'replacement_committed_at' => $replacementCommittedAt,
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

        'shm_memorandum_submit' => (function () use ($params) {
            $user = User::findOrFail($params['user_id']);
            $memorandum = ShmMemorandum::findOrFail($params['memorandum_id']);
            $approvalService = app(ShmMemorandumApprovalService::class);

            $submitted = $approvalService->submit($memorandum, $user, $params['notes'] ?? null);

            $latestSubmission = $submitted->submissions()->latest('revision_number')->first();

            return [
                'memorandum_id' => $submitted->id,
                'status' => $submitted->status,
                'revision_number' => $latestSubmission?->revision_number,
                'content_hash' => $latestSubmission?->content_hash,
            ];
        })(),

        'shm_exception_consume' => (function () use ($params) {
            $application = ShmApplication::findOrFail($params['application_id']);
            $memorandum = ShmMemorandum::findOrFail($params['memorandum_id']);
            $exceptionService = app(ShmCreditCheckExceptionService::class);

            $consumed = $exceptionService->consumeException($application, $params['exception_type'], $memorandum);

            return [
                'exception_id' => $consumed->id,
                'status' => $consumed->status,
                'consumed_memorandum_id' => $consumed->consumed_memorandum_id,
                'pid' => getmypid(),
                'db_connection' => config('database.default'),
                'db_database' => config('database.connections.'.config('database.default').'.database'),
                'started_at' => microtime(true),
            ];
        })(),

        default => throw new InvalidArgumentException("Tugas worker tidak dikenal: {$task}"),
    };

    emit(['ok' => true] + $result);
} catch (ValidationException $e) {
    emit(['ok' => false, 'kind' => 'validation', 'errors' => $e->errors(), 'pid' => getmypid(), 'db_connection' => config('database.default'), 'db_database' => config('database.connections.'.config('database.default').'.database')]);
} catch (QueryException $e) {
    emit(['ok' => false, 'kind' => 'query', 'sqlstate' => $e->getCode(), 'message' => mb_substr($e->getMessage(), 0, 300), 'pid' => getmypid()]);
} catch (Throwable $e) {
    emit(['ok' => false, 'kind' => 'error', 'message' => mb_substr($e->getMessage(), 0, 300), 'pid' => getmypid()]);
}

exit(0);
