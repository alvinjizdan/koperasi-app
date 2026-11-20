<?php

namespace App\Support;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;
use Throwable;

/**
 * Pembungkus transaksi database dengan retry terbatas.
 *
 * Operasi lintas modul (saldo simpanan, pencairan, pembayaran, kas) memakai
 * row locking SELECT ... FOR UPDATE. Pada beban konkuren, PostgreSQL dapat
 * melempar deadlock (40P01) atau serialization failure (40001) ketika dua
 * proses mengunci baris dalam urutan berbeda. Kegagalan tersebut bersifat
 * sementara: seluruh transaksi dibatalkan (tidak ada perubahan parsial) dan
 * aman untuk diulang.
 */
class DbTransaction
{
    /**
     * Jumlah percobaan maksimum percobaan awal dihitung sebagai 1.
     */
    private const MAX_ATTEMPTS = 3;

    /**
     * SQLSTATE transient yang aman diulang pada level transaksi terluar.
     *
     * @var array<int, string>
     */
    private const RETRYABLE_SQL_STATES = ['40P01', '40001'];

    /**
     * Jalankan callback dalam transaksi database; ulangi bila gagal karena
     * deadlock atau serialization failure. Kegagalan lain langsung dilempar.
     *
     * Jika dipanggil di dalam transaksi luar yang sudah aktif (DB::transactionLevel() > 0),
     * jangan lakukan retry inner callback secara otomatis karena kegagalan seperti
     * deadlock (40P01) atau serialization failure (40001) pada PostgreSQL membatalkan
     * seluruh transaksi luar atau savepoint snapshot. Biarkan exception merambat ke
     * pemilik transaksi luar (top-level) yang memiliki otoritas untuk mengulang
     * keseluruhan alur secara utuh dan aman.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @param  (Closure(Throwable, int, string): void)|null  $onRetry  Dipanggil tepat sebelum percobaan ulang.
     * @return TReturn
     */
    public static function run(Closure $callback, ?Closure $onRetry = null): mixed
    {
        // Jika sudah di dalam transaksi luar, gunakan transaksi/savepoint bawaan Laravel
        // tanpa local retry loop.
        if (DB::transactionLevel() > 0) {
            return DB::transaction($callback);
        }

        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return DB::transaction($callback);
            } catch (Throwable $e) {
                $sqlState = self::extractSqlState($e);

                $retryable = $sqlState !== null
                    && in_array($sqlState, self::RETRYABLE_SQL_STATES, true)
                    && $attempt < self::MAX_ATTEMPTS;

                if (! $retryable) {
                    throw $e;
                }

                if ($onRetry !== null) {
                    $onRetry($e, $attempt, $sqlState);
                }

                // Jeda singkat sebelum mencoba lagi agar transaksi pemilik lock
                // sempat menyelesaikan pekerjaannya.
                usleep(random_int(50_000, 150_000) * $attempt);
            }
        }
    }

    /**
     * Ekstrak SQLSTATE dari exception yang benar-benar dilempar Laravel/PDO.
     *
     * QueryException menyimpan SQLSTATE di properti errorInfo (warisan PDOException)
     * dan pada code ketika driver mengisinya sebagai string. Wrapper lain dapat
     * menyembunyikan SQLSTATE pada previous exception, sehingga seluruh rantai
     * exception diperiksa.
     */
    public static function extractSqlState(Throwable $e): ?string
    {
        $current = $e;

        while ($current !== null) {
            if ($current instanceof QueryException || $current instanceof PDOException) {
                $errorInfoState = $current->errorInfo[0] ?? null;

                if (is_string($errorInfoState) && self::isSqlState($errorInfoState)) {
                    return $errorInfoState;
                }
            }

            $code = $current->getCode();

            if (is_string($code) && self::isSqlState($code)) {
                return $code;
            }

            $current = $current->getPrevious();
        }

        return null;
    }

    /**
     * SQLSTATE selalu lima karakter alfanumerik kapital (contoh 40P01, 40001, 23505).
     */
    private static function isSqlState(string $value): bool
    {
        return preg_match('/^[0-9A-Z]{5}$/', $value) === 1;
    }
}
