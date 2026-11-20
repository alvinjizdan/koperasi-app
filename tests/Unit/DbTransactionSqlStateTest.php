<?php

namespace Tests\Unit;

use App\Support\DbTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use PDOException;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * TICKET 42C-F Scope B: pembuktian ekstraksi SQLSTATE dan kebijakan retry.
 */
class DbTransactionSqlStateTest extends TestCase
{
    public function test_sqlstate_is_extracted_from_query_exception_error_info(): void
    {
        $exception = $this->queryException('40P01');

        $this->assertSame('40P01', DbTransaction::extractSqlState($exception));
    }

    public function test_sqlstate_is_extracted_from_previous_exception_chain(): void
    {
        $queryException = $this->queryException('40001');
        $wrapper = new RuntimeException('service wrapper', 0, $queryException);

        $this->assertSame('40001', DbTransaction::extractSqlState($wrapper));
    }

    public function test_sqlstate_is_extracted_from_pdo_code_string(): void
    {
        $exception = new PdoSqlStateException('40001');

        $this->assertSame('40001', DbTransaction::extractSqlState($exception));
    }

    public function test_deadlock_is_retried_and_callback_restarts_from_beginning(): void
    {
        $attempts = 0;
        $retries = [];

        $result = DbTransaction::run(function () use (&$attempts) {
            $attempts++;

            if ($attempts === 1) {
                throw $this->queryException('40P01');
            }

            return 'committed';
        }, function (Throwable $e, int $attempt, string $sqlState) use (&$retries): void {
            $retries[] = [$attempt, $sqlState];
        });

        $this->assertSame('committed', $result);
        $this->assertSame(2, $attempts);
        $this->assertSame([[1, '40P01']], $retries);
    }

    public function test_serialization_failure_is_retried(): void
    {
        $attempts = 0;

        $result = DbTransaction::run(function () use (&$attempts) {
            $attempts++;

            if ($attempts === 1) {
                throw $this->queryException('40001');
            }

            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertSame(2, $attempts);
    }

    public function test_business_validation_exception_is_not_retried(): void
    {
        $attempts = 0;

        try {
            DbTransaction::run(function () use (&$attempts) {
                $attempts++;
                throw ValidationException::withMessages(['amount' => ['Nominal tidak valid.']]);
            });
            $this->fail('ValidationException harus diteruskan.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(1, $attempts);
    }

    public function test_other_connection_error_is_not_retried(): void
    {
        $attempts = 0;

        try {
            DbTransaction::run(function () use (&$attempts) {
                $attempts++;
                throw $this->queryException('08006');
            });
            $this->fail('Connection exception harus diteruskan.');
        } catch (QueryException) {
            // expected
        }

        $this->assertSame(1, $attempts);
    }

    public function test_unknown_exception_is_propagated_without_retry(): void
    {
        $attempts = 0;

        try {
            DbTransaction::run(function () use (&$attempts) {
                $attempts++;
                throw new RuntimeException('unexpected failure');
            });
            $this->fail('RuntimeException harus diteruskan.');
        } catch (RuntimeException $e) {
            $this->assertSame('unexpected failure', $e->getMessage());
        }

        $this->assertSame(1, $attempts);
    }

    public function test_retry_exhaustion_uses_exact_max_attempts_and_rethrows_original(): void
    {
        $attempts = 0;
        $retries = [];
        $thrown = $this->queryException('40P01');

        try {
            DbTransaction::run(function () use (&$attempts, $thrown) {
                $attempts++;
                throw $thrown;
            }, function (Throwable $e, int $attempt, string $sqlState) use (&$retries): void {
                $retries[] = [$attempt, $sqlState];
            });
            $this->fail('Retry exhaustion harus meneruskan exception asli.');
        } catch (QueryException $e) {
            $this->assertSame($thrown, $e);
        }

        $this->assertSame(3, $attempts);
        $this->assertSame([[1, '40P01'], [2, '40P01']], $retries);
    }

    public function test_exhaustion_propagates_serialization_failure_sqlstate(): void
    {
        try {
            DbTransaction::run(fn () => throw $this->queryException('40001'));
            $this->fail('Harus melempar QueryException.');
        } catch (QueryException $e) {
            $this->assertSame('40001', DbTransaction::extractSqlState($e));
        }
    }

    private function queryException(string $sqlState): QueryException
    {
        $pdo = new PDOException('database error');
        $pdo->errorInfo = [$sqlState, 7, 'database error'];

        return new QueryException('sqlite', 'select 1', [], $pdo);
    }
}

/**
 * PDOException dengan code string seperti yang dihasilkan driver pgsql.
 */
class PdoSqlStateException extends PDOException
{
    public function __construct(string $sqlState)
    {
        parent::__construct('database error');

        $this->code = $sqlState;
        $this->errorInfo = [$sqlState, 7, 'database error'];
    }
}
