<?php

namespace App\Services\Shm;

use App\Models\ShmLoan;
use App\Models\ShmLoanPayment;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Ticket 86 — Pembuatan transaksi pembayaran pinjaman SHM.
 *
 * Source of truth pembayaran. SATU transaksi database berisi: lock baris
 * pinjaman (lockForUpdate), guard sumber pembayaran, pembuatan tepat SATU
 * ShmLoanPayment, dan tepat SATU baris audit. Bila salah satu bagian gagal,
 * tidak ada satu pun bagian yang tertulis (rollback penuh — tanpa pembayaran
 * atau audit setengah matang).
 *
 * YANG TIDAK DILAKUKAN ticket ini (sengaja):
 * - alokasi ke angsuran (principal/bunga/denda) — Ticket 87;
 * - perubahan paid_amount / remaining_amount / status angsuran;
 * - perubahan saldo pokok, penutupan pinjaman, pelunasan, settlement;
 * - pembatasan nominal ke angsuran — pembayaran MELEBIHI angsuran (overpayment)
 *   disimpan apa adanya; perlakuan kelebihannya adalah kontrak T87.
 *
 * IDEMPOTENCY (T84): mengikuti pola existing koperasi
 * (`InstallmentPaymentService`): bila `idempotency_key` sudah terdaftar,
 * request ulang MENGEMBALIKAN pembayaran pertama — tidak pernah membuat
 * pembayaran kedua, maupun audit kedua. Dilindungi berlapis: (1) cek di
 * luar transaksi, (2) cek ulang SETELAH lock, (3) constraint UNIQUE di
 * database untuk request paralel.
 *
 * GUARD SUMBER: pembayaran HANYA terhadap pinjaman SHM yang sudah dicairkan
 * (`disbursed_at` terisi) dan masih `active`. Pinjaman `closed` dan pinjaman
 * yang belum cair ditolak — pembayaran TIDAK PERNAH dibuat terhadap
 * ShmLoanApplication.
 *
 * Nominal > 0 divalidasi berlapis (FormRequest + service), dan TIDAK PERNAH
 * dihitung ulang dari angsuran oleh backend.
 */
class ShmLoanPaymentService
{
    /** Percobaan pembuatan nomor referensi sebelum dianggap gagal. */
    private const REFERENCE_ATTEMPTS = 5;

    /**
     * Catat SATU transaksi pembayaran terhadap pinjaman SHM.
     *
     * @param  array{
     *     payment_date: string,
     *     amount: numeric,
     *     payment_channel?: string|null,
     *     notes?: string|null,
     *     idempotency_key?: string|null
     * }  $data
     *
     * @throws ValidationException bila guard sumber/nominal menolak
     * @throws \Throwable bila pembuatan pembayaran/audit gagal (rollback penuh)
     */
    public function create(ShmLoan $loan, array $data, int $actorId): ShmLoanPayment
    {
        $idempotencyKey = $data['idempotency_key'] ?? null;

        // 1. Idempotensi (luar transaksi): request ulang membalikkan baris
        //    yang sama, bukan pembayaran kedua.
        if (! empty($idempotencyKey)) {
            $existing = ShmLoanPayment::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($loan, $data, $actorId, $idempotencyKey): ShmLoanPayment {
            // 2. Lock baris pinjaman — state dibaca di dalam transaksi, bukan
            //    dari state UI yang mungkin sudah basi. Mencegah bayar terhadap
            //    pinjaman yang baru saja ditutup secara bersamaan.
            $locked = ShmLoan::query()->lockForUpdate()->findOrFail($loan->id);

            // 3. Cek idempotensi SETELAH lock: request paralel dengan kunci
            //    yang sama tetap membalikkan pembayaran pertama.
            if (! empty($idempotencyKey)) {
                $existing = ShmLoanPayment::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            // 4. Guard sumber: hanya pinjaman yang SUDAH dicairkan.
            if ($locked->disbursed_at === null) {
                throw ValidationException::withMessages([
                    'loan_id' => sprintf(
                        'Pinjaman %s belum dicairkan sehingga tidak dapat menerima pembayaran.',
                        $locked->loan_number,
                    ),
                ]);
            }

            // 5. Guard sumber: hanya pinjaman yang masih berjalan. Pinjaman
            //    closed ditolak (tidak ada pembayaran baru setelah tutup).
            if ($locked->status !== ShmLoan::STATUS_ACTIVE) {
                throw ValidationException::withMessages([
                    'loan_id' => sprintf(
                        'Pinjaman %s sudah ditutup sehingga tidak dapat menerima pembayaran.',
                        $locked->loan_number,
                    ),
                ]);
            }

            // 6. Guard nominal (pertahanan kedua setelah FormRequest):
            //    harus numerik dan lebih besar dari 0. TIDAK dibatasi ke
            //    angsuran — overpayment disimpan persis (kontrak T87).
            $amount = $data['amount'];

            if (! is_numeric($amount) || (float) $amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Nominal pembayaran harus berupa angka dan lebih besar dari 0.',
                ]);
            }

            // 7. Buat tepat SATU transaksi pembayaran. Referensi dibuat
            //    server-side (bukan payload React) dan dijaga UNIQUE di
            //    database.
            $payment = ShmLoanPayment::create([
                'shm_loan_id' => $locked->id,
                'payment_reference' => $this->generateReference(),
                'payment_date' => $data['payment_date'],
                // Disimpan PERSIS sesuai precision desimal finansial (18,2):
                // tidak ditrunk, tidak dibulatkan diam-diam.
                'amount' => $amount,
                // Kanal resmi PENDING BUSINESS CONFIRMATION — nullable.
                'payment_channel' => $data['payment_channel'] ?? null,
                'status' => ShmLoanPayment::STATUS_POSTED,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actorId,
                'idempotency_key' => $idempotencyKey,
            ]);

            // 8. Tepat SATU baris audit pembayaran, di dalam transaksi yang
            //    sama (rollback bila pembayaran gagal). Tidak ada audit
            //    alokasi/reversal pada ticket ini.
            AuditLogger::log(
                'shm.loan.payment.created',
                ShmLoanPayment::class,
                $payment->id,
                $payment->payment_reference,
                sprintf(
                    'Pembayaran Rp %s dicatat untuk pinjaman %s (referensi %s).',
                    number_format((float) $payment->amount, 0, ',', '.'),
                    $locked->loan_number,
                    $payment->payment_reference,
                ),
                [
                    'shm_loan_id' => $locked->id,
                    'loan_number' => $locked->loan_number,
                    'payment_reference' => $payment->payment_reference,
                    'amount' => (string) $payment->amount,
                    'payment_date' => $payment->payment_date?->toDateString(),
                    'payment_channel' => $payment->payment_channel,
                    'status' => $payment->status,
                ],
            );

            return $payment;
        });
    }

    /**
     * Nomor referensi pembayaran baru, dibuat di server.
     *
     * Format `SHM-PAY-YYYYMMDD-XXXXXXXX` mengikuti pola identifiker SHM
     * (T67 pengajuan, T69 pinjaman): komponen acak dari random_bytes sehingga
     * tidak dapat ditebak dan tidak bentrok antar request paralel.
     *
     * @throws RuntimeException bila setelah beberapa percobaan tetap bentrok
     */
    private function generateReference(): string
    {
        for ($attempt = 0; $attempt < self::REFERENCE_ATTEMPTS; $attempt++) {
            $candidate = sprintf(
                'SHM-PAY-%s-%s',
                now()->format('Ymd'),
                strtoupper(bin2hex(random_bytes(4))),
            );

            if (! ShmLoanPayment::query()->where('payment_reference', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException(
            'Referensi pembayaran SHM tidak dapat dibuat setelah beberapa percobaan.'
        );
    }
}
