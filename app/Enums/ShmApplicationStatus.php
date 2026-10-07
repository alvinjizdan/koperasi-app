<?php

namespace App\Enums;

/**
 * Status lifecycle pengajuan SHM/SHGB.
 *
 * Workflow ini adalah workflow TEKNIS MVP agar sistem dapat dipakai dan didemokan.
 * Ini BUKAN SOP resmi KSP dan tidak boleh diperlakukan sebagai aturan bisnis
 * yang final. Peta transisi sengaja dibuat eksplisit supaya sistem tidak
 * menerima transisi bebas dari UI.
 *
 * Transisi tidak reversible secara otomatis. Kebutuhan untuk kembali ke
 * tahap sebelumnya dicatat sebagai PENDING BUSINESS DECISION karena belum
 * ada aturan dari KSP soal siapa yang boleh membatalkan atau memperbaiki
 * pengajuan yang sudah berjalan.
 */
enum ShmApplicationStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case CreditChecking = 'credit_checking';
    case Memorandum = 'memorandum';
    case Approval = 'approval';
    case Approved = 'approved';
    case DocumentCheck = 'document_check';
    case Rejected = 'rejected';
    case ReadyForDisbursement = 'ready_for_disbursement';
    case Disbursed = 'disbursed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * Label status untuk ditampilkan pada UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Disubmit',
            self::CreditChecking => 'Credit Checking',
            self::Memorandum => 'Memorandum',
            self::Approval => 'Approval',
            self::Approved => 'Disetujui',
            self::DocumentCheck => 'Kelengkapan Dokumen',
            self::Rejected => 'Ditolak',
            self::ReadyForDisbursement => 'Siap Pencairan',
            self::Disbursed => 'Dicairkan',
            self::Completed => 'Selesai',
            self::Cancelled => 'Dibatalkan',
        };
    }

    /**
     * Transisi yang diizinkan dari status ini.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted, self::Cancelled],
            self::Submitted => [self::CreditChecking, self::Cancelled],
            self::CreditChecking => [self::Memorandum],
            self::Memorandum => [self::Approval],
            self::Approval => [self::Approved, self::Rejected],
            self::Approved => [self::DocumentCheck],
            self::DocumentCheck => [self::ReadyForDisbursement],
            self::ReadyForDisbursement => [self::Disbursed],
            self::Disbursed => [self::Completed],
            self::Rejected => [self::Draft],
            self::Completed => [],
            self::Cancelled => [],
        };
    }

    /**
     * Apakah transisi ke status tujuan diizinkan.
     */
    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Status terminal: tidak ada transisi keluar kecuali pengajuan ditolak.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }

    /**
     * Status penolakan.
     */
    public function isRejected(): bool
    {
        return $this === self::Rejected;
    }

    /**
     * Urutan status untuk ditampilkan sebagai timeline.
     *
     * Status yang tidak ada dalam alur normal (rejected, cancelled)
     * tidak muncul di garis waktu utama.
     *
     * @return array<int, self>
     */
    public static function timeline(): array
    {
        return [
            self::Draft,
            self::Submitted,
            self::CreditChecking,
            self::Memorandum,
            self::Approval,
            self::Approved,
            self::DocumentCheck,
            self::ReadyForDisbursement,
            self::Disbursed,
            self::Completed,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
