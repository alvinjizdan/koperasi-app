<?php

namespace App\Services\Shm;

use App\Models\ShmLoan;
use RuntimeException;

/**
 * Ticket 75 — Konfigurasi bisnis jadwal angsuran SHM belum lengkap.
 *
 * Exception ini adalah kegagalan DETERMINISTIK dan dapat dijelaskan: service
 * jadwal angsuran TIDAK PERNAH menebak formula, periode rate, frekuensi,
 * aturan due-date, pembulatan, atau penyesuaian angsuran terakhir. Bila satu
 * pun keputusan tersebut belum dikonfirmasi bisnis (lihat
 * config/shm-business-rule-contract.php), pembangkitan jadwal ditolak dengan
 * exception ini — tanpa fallback, tanpa nilai 0, tanpa jadwal parsial.
 */
class ScheduleConfigurationIncomplete extends RuntimeException
{
    /**
     * @param  array<int, string>  $missingDecisions
     */
    public static function forLoan(ShmLoan $loan, array $missingDecisions): self
    {
        return new self(sprintf(
            'Jadwal angsuran pinjaman %s belum dapat dibuat karena keputusan bisnis berikut belum dikonfirmasi: %s. '
            .'Tidak ada default yang ditebak dan tidak ada baris angsuran yang ditulis (PENDING BUSINESS CONFIRMATION).',
            $loan->loan_number,
            implode('; ', array_values($missingDecisions)),
        ));
    }
}
