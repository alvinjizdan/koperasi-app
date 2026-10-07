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
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';

    /**
     * Label status untuk ditampilkan pada UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Belum Dimulai',
            self::InProgress => 'Sedang Berjalan',
            self::Completed => 'Selesai',
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
            self::Pending => [self::InProgress],
            self::InProgress => [self::Completed],
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

    public function isPending(): bool
    {
        return $this === self::Pending;
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
     *
     * Dipetakan di server supaya warna badge tidak ditentukan ulang di
     * setiap halaman.
     */
    public function badgeKey(): string
    {
        return match ($this) {
            self::Pending => 'PENDING',
            self::InProgress => 'IN_PROGRESS',
            self::Completed => 'COMPLETED',
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
