<?php

namespace App\Services\Reports;

/**
 * Daftar laporan dasar yang tersedia beserta service penyedianya.
 *
 * Registry ini menjadi satu-satunya sumber kebenaran untuk slug laporan, label,
 * dan nama file ekspor sehingga halaman laporan dan endpoint ekspor tidak dapat
 * berbeda satu sama lain.
 */
final class ReportRegistry
{
    public const MEMBERS = 'anggota';

    public const SAVINGS = 'simpanan';

    public const LOANS = 'pinjaman';

    public const INSTALLMENTS = 'angsuran';

    public const OVERDUE = 'tunggakan';

    public const CASH_TRANSACTIONS = 'transaksi-kas';

    public const CASH_DAILY = 'kas-harian';

    /**
     * @return array<int, string>
     */
    public static function slugs(): array
    {
        return [
            self::MEMBERS,
            self::SAVINGS,
            self::LOANS,
            self::INSTALLMENTS,
            self::OVERDUE,
            self::CASH_TRANSACTIONS,
            self::CASH_DAILY,
        ];
    }

    public static function exists(string $report): bool
    {
        return in_array($report, self::slugs(), true);
    }

    public static function label(string $report): string
    {
        return match ($report) {
            self::MEMBERS => 'Laporan Anggota',
            self::SAVINGS => 'Laporan Simpanan',
            self::LOANS => 'Laporan Pinjaman',
            self::INSTALLMENTS => 'Laporan Pembayaran Angsuran',
            self::OVERDUE => 'Laporan Tunggakan',
            self::CASH_TRANSACTIONS => 'Laporan Transaksi Kas',
            self::CASH_DAILY => 'Laporan Kas Harian',
            default => 'Laporan',
        };
    }

    /**
     * Service laporan sesuai slug.
     */
    public static function make(string $report, ReportPeriod $period): MemberReport|SavingsReport|LoanReport|InstallmentReport|OverdueReport|CashTransactionReport|CashDailyReport
    {
        return match ($report) {
            self::MEMBERS => new MemberReport,
            self::SAVINGS => new SavingsReport($period),
            self::LOANS => new LoanReport($period),
            self::INSTALLMENTS => new InstallmentReport($period),
            self::OVERDUE => new OverdueReport,
            self::CASH_TRANSACTIONS => new CashTransactionReport($period),
            self::CASH_DAILY => new CashDailyReport($period),
            default => throw new \InvalidArgumentException("Laporan [{$report}] tidak dikenal."),
        };
    }

    /**
     * Nama file ekspor yang menyertakan slug dan periode aktif.
     */
    public static function filename(string $report, ReportPeriod $period): string
    {
        return sprintf('laporan-%s-%s_sd_%s.csv', $report, $period->fromDate(), $period->toDate());
    }

    /**
     * Apakah laporan memakai filter periode (untuk tombol ekspor dan filter UI).
     */
    public static function usesPeriod(string $report): bool
    {
        return ! in_array($report, [self::MEMBERS, self::OVERDUE], true);
    }
}
