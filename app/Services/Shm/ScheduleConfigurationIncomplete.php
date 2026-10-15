<?php

namespace App\Services\Shm;

use App\Models\ShmLoan;
use RuntimeException;

/**
 * Ticket 75 — Konfigurasi perhitungan jadwal angsuran SHM tidak lengkap.
 *
 * Exception ini adalah kegagalan DETERMINISTIK dan dapat dijelaskan: service
 * jadwal angsuran TIDAK PERNAH menebak formula, periode rate, frekuensi,
 * aturan due-date, pembulatan, atau penyesuaian angsuran terakhir. Bila satu
 * pun kontrak perhitungan tersebut tidak lengkap atau tidak sesuai baseline
 * resmi (flat + monthly, T79 — lihat config/shm-business-rule-contract.php),
 * pembangkitan jadwal ditolak dengan exception ini — tanpa fallback, tanpa
 * nilai 0, tanpa jadwal parsial.
 */
class ScheduleConfigurationIncomplete extends RuntimeException
{
    /**
     * @param  array<int, string>  $missingDecisions
     */
    public static function forLoan(ShmLoan $loan, array $missingDecisions): self
    {
        return new self(sprintf(
            'Jadwal angsuran pinjaman %s belum dapat dibuat karena konfigurasi perhitungan berikut tidak lengkap atau tidak sesuai baseline: %s. '
            .'Tidak ada default yang ditebak dan tidak ada baris angsuran yang ditulis.',
            $loan->loan_number,
            implode('; ', array_values($missingDecisions)),
        ));
    }
}
