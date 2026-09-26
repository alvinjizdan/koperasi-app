<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\DB;
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
     * Jalankan callback dalam transaksi database; ulangi bila gagal karena
     * deadlock atau serialization failure. Kegagalan lain langsung dilempar.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function run(Closure $callback): mixed
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return DB::transaction($callback);
            } catch (Throwable $e) {
                $sqlState = $e->getCode();

                $retryable = in_array($sqlState, ['40P01', '40001'], true)
                    && $attempt < self::MAX_ATTEMPTS;

                if (! $retryable) {
                    throw $e;
                }

                // Jeda singkat sebelum mencoba lagi agar transaksi pemilik lock
                // sempat menyelesaikan pekerjaannya.
                usleep(random_int(50_000, 150_000) * $attempt);
            }
        }
    }
}
