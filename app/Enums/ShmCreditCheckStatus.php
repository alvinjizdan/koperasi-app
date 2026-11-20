<?php

namespace App\Enums;

/**
 * Status proses credit checking pengajuan SHM.
 *
 * Ini adalah status WORKFLOW MANUAL, BUKAN penilaian kredit. Tidak ada skor,
 * ambang angka, blacklist, atau keputusan otomatis. Integrasi BI Checking /
 * SLIK / OJK belum ada dan bukan bagian dari ticket ini.
 *
 * Rantai transisi sengaja dibuat eksplisit supaya proses tidak dapat melompat:
 *
 *   Pending -> InProgress -> Completed
 *
 * Transisi `Pending -> Completed` SENGAJA TIDAK DIIZINKAN: hasil checking harus
 * melalui tahap pengerjaan sehingga selalu ada catatan siapa mulai dan kapan.
 *
 * `Completed` bersifat terminal. Membuka kembali hasil yang sudah selesai
 * (reopen/rollback) belum diputuskan KSP, sehingga tidak disediakan.
 *
 * Penyelesaian credit checking TIDAK mengubah status pengajuan SHM. Keputusan
 * setuju/tolak tetap melalui workflow Approval (ShmApplicationStatus).
 */
enum ShmCreditCheckStatus: string
{
    // Legacy cases (mapped for backward compatibility)
    case Pending = 'pending';
    case InProgress = 'in_progress';

    // Simplified CC-09 cases
    case Drafted = 'drafted';
    case Approve = 'approve';
    case Rejected = 'rejected';
    case Completed = 'completed';

    /**
     * Label status untuk ditampilkan pada UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::Drafted, self::Pending => 'Drafted',
            self::Approve => 'Approve',
            self::Rejected => 'Rejected',
            self::InProgress => 'Sedang Berjalan',
            self::Completed => 'Completed',
        };
    }

    /**
     * Transisi yang diizinkan dari status ini (CC-09 state matrix).
     *
     * drafted -> approve | rejected
     * rejected -> drafted
     * approve -> completed
     * completed -> []
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::InProgress],
            self::Drafted => [self::Approve, self::Rejected],
            self::InProgress => [self::Completed],
            self::Approve => [self::Completed],
            self::Rejected => [self::Drafted],
            self::Completed => [],
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
     * Status terminal: tidak ada transisi keluar.
     */
    public function isFinal(): bool
    {
        return $this === self::Completed;
    }

    public function isDrafted(): bool
    {
        return $this === self::Drafted || $this === self::Pending;
    }

    public function isApprove(): bool
    {
        return $this === self::Approve;
    }

    public function isRejected(): bool
    {
        return $this === self::Rejected;
    }

    public function isPending(): bool
    {
        return $this === self::Pending || $this === self::Drafted;
    }

    public function isInProgress(): bool
    {
        return $this === self::InProgress;
    }

    public function isCompleted(): bool
    {
        return $this === self::Completed;
    }

    /**
     * Kunci warna badge pada komponen StatusBadge frontend.
     */
    public function badgeKey(): string
    {
        return match ($this) {
            self::Drafted, self::Pending => 'DRAFTED',
            self::Approve => 'APPROVED',
            self::Rejected => 'REJECTED',
            self::InProgress => 'IN_PROGRESS',
            self::Completed => 'COMPLETED',
        };
    }

    /**
     * Normalisasi string status (termasuk legacy).
     */
    public static function normalize(string|self $status): self
    {
        if ($status instanceof self) {
            return $status;
        }

        return match ($status) {
            'pending' => self::Drafted,
            'in_progress' => self::InProgress,
            'drafted' => self::Drafted,
            'approve' => self::Approve,
            'rejected' => self::Rejected,
            'completed' => self::Completed,
            default => self::from($status),
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
