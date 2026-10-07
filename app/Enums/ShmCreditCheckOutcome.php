<?php

namespace App\Enums;

/**
 * Hasil pencatatan credit checking pengajuan SHM.
 *
 * PENTING: outcome di sini HANYA hasil pencatatan manual oleh petugas.
 * Ini BUKAN keputusan kelayakan kredit, bukan scoring, dan tidak memicu
 * approval atau penolakan otomatis. Keputusan setuju/tolak pengajuan tetap
 * dilakukan melalui workflow Approval.
 *
 * Nilai disimpan huruf kecil (mengikuti konvensi nilai status lain di
 * aplikasi), sedangkan label ditampilkan huruf besar sesuai istilah yang
 * dipakai KSP pada dokumen operasional: PASS, REVIEW, FAIL.
 *
 * Arti operasional yang belum dikunci KSP dan karena itu TIDAK ditegakkan
 * kode: apakah REVIEW wajib ditindaklanjuti, apakah FAIL otomatis menolak
 * pengajuan, dan siapa yang berwenang menetapkan standar penilaian.
 */
enum ShmCreditCheckOutcome: string
{
    case Pass = 'pass';
    case Review = 'review';
    case Fail = 'fail';

    /**
     * Label hasil untuk ditampilkan pada UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pass => 'PASS',
            self::Review => 'REVIEW',
            self::Fail => 'FAIL',
        };
    }

    /**
     * Keterangan singkat hasil, bukan penilaian kelayakan.
     */
    public function description(): string
    {
        return match ($this) {
            self::Pass => 'Tidak ditemukan hal yang perlu ditinjau.',
            self::Review => 'Perlu ditinjau lebih lanjut.',
            self::Fail => 'Ditemukan hal yang tidak memenuhi.',
        };
    }

    /**
     * Kunci warna badge pada komponen StatusBadge frontend.
     */
    public function badgeKey(): string
    {
        return match ($this) {
            self::Pass => 'PASS',
            self::Review => 'REVIEW',
            self::Fail => 'FAIL',
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
