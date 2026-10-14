<?php

namespace App\Services\Shm;

use App\Models\ShmLoan;
use RuntimeException;

/**
 * Ticket 89 — Konfirmasi settlement ditahan karena kontrak bisnis belum lengkap.
 *
 * Dilempar oleh ShmLoanSettlementService::settle() selama
 * `shm-business-rule-contract.settlement.blocked_by` masih berisi item
 * PENDING BUSINESS CONFIRMATION. Penolakan ini DETERMINISTIK: tidak ada
 * record settlement, audit, perubahan angsuran/pembayaran, maupun penutupan
 * pinjaman yang ditulis oleh penolakan ini.
 *
 * Pesan selalu memuat: nomor pinjaman, penanda "PENDING BUSINESS CONFIRMATION",
 * dan daftar item blocked_by kontrak — sehingga pengguna tahu BUKAN apa yang
 * menghalangi, tanpa menebak aturan yang belum dikonfirmasi.
 */
class SettlementContractIncomplete extends RuntimeException
{
    /**
     * @param  array<int, string>  $blockedItems
     */
    public function __construct(
        public readonly ShmLoan $loan,
        public readonly array $blockedItems,
    ) {
        parent::__construct(sprintf(
            'Pelunasan pinjaman %s belum dapat dikonfirmasi: PENDING BUSINESS CONFIRMATION — %s.',
            $loan->loan_number,
            implode('; ', $blockedItems),
        ));
    }
}
