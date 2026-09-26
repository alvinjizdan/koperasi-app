<?php

namespace App\Services\Reports;

use App\Services\SettingsService;
use Illuminate\Support\Carbon;

/**
 * Periode laporan untuk metrik berbasis transaksi.
 *
 * Batas periode dihitung pada zona waktu operasional (config reports.operational_timezone,
 * atau app.timezone bila tidak diset) sehingga seluruh laporan memakai cakupan yang sama.
 * Filter periode tidak pernah mengubah saldo berjalan (posisi saat ini), hanya
 * memengaruhi metrik transaksi di dalam periode.
 */
final class ReportPeriod
{
    public const PRESET_TODAY = 'today';

    public const PRESET_LAST_7_DAYS = 'last_7_days';

    public const PRESET_THIS_MONTH = 'this_month';

    public const PRESET_LAST_MONTH = 'last_month';

    public const PRESET_CUSTOM = 'custom';

    /**
     * @var array<int, string>
     */
    public const PRESETS = [
        self::PRESET_TODAY,
        self::PRESET_LAST_7_DAYS,
        self::PRESET_THIS_MONTH,
        self::PRESET_LAST_MONTH,
        self::PRESET_CUSTOM,
    ];

    private function __construct(
        public readonly string $preset,
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly string $timezone,
    ) {}

    /**
     * Bangun periode dari filter yang sudah divalidasi.
     *
     * @param  array<string, mixed>  $filters
     */
    public static function fromFilters(array $filters): self
    {
        $timezone = self::timezone();
        $today = self::today();
        $preset = $filters['period'] ?? self::PRESET_THIS_MONTH;

        if (! in_array($preset, self::PRESETS, true)) {
            $preset = self::PRESET_THIS_MONTH;
        }

        [$from, $to] = match ($preset) {
            self::PRESET_TODAY => [$today->copy(), $today->copy()],
            self::PRESET_LAST_7_DAYS => [$today->copy()->subDays(6), $today->copy()],
            self::PRESET_LAST_MONTH => [
                $today->copy()->subMonthNoOverflow()->startOfMonth(),
                $today->copy()->subMonthNoOverflow()->endOfMonth(),
            ],
            self::PRESET_CUSTOM => [
                self::parseDate($filters['date_from'] ?? null, $today->copy()->startOfMonth()),
                self::parseDate($filters['date_to'] ?? null, $today),
            ],
            default => [$today->copy()->startOfMonth(), $today->copy()],
        };

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return new self($preset, $from->startOfDay(), $to->startOfDay(), $timezone);
    }

    /**
     * Zona waktu operasional yang dipakai seluruh laporan.
     *
     * Prioritas: pengaturan di database (halaman Pengaturan), lalu env
     * APP_OPERATIONAL_TIMEZONE, lalu app.timezone.
     */
    public static function timezone(): string
    {
        $setting = null;

        try {
            $setting = app(SettingsService::class)->get('operational_timezone');
        } catch (\Throwable) {
            // Tabel settings belum tersedia (mis. saat migrasi berjalan).
            $setting = '';
        }

        return (string) ($setting ?: config('reports.operational_timezone') ?: config('app.timezone'));
    }

    /**
     * Tanggal operasional hari ini pada zona waktu operasional.
     */
    public static function today(): Carbon
    {
        return Carbon::now(self::timezone())->startOfDay();
    }

    public static function todayDate(): string
    {
        return self::today()->toDateString();
    }

    public function fromDate(): string
    {
        return $this->from->toDateString();
    }

    public function toDate(): string
    {
        return $this->to->toDateString();
    }

    /**
     * Batas rentang inklusif yang aman untuk kolom bertipe date.
     *
     * Nilai yang tersimpan berbeda antar cast: cast 'date:Y-m-d' menyimpan 'Y-m-d'
     * sedangkan cast 'date' menyimpan 'Y-m-d H:i:s'. Batas bawah dibiarkan tanpa
     * jam dan batas atas memakai akhir hari sehingga kedua bentuk sama-sama ikut
     * terhitung, termasuk pada hari pertama dan terakhir periode.
     *
     * Batas ini juga tetap dapat memakai indeks: pada PostgreSQL literal string
     * dikonversi ke tipe date di sisi database, bukan dengan membungkus kolom
     * memakai fungsi seperti whereDate().
     *
     * @return array{0: string, 1: string}
     */
    public function dateRange(): array
    {
        return [$this->fromDate(), $this->toDate().' 23:59:59'];
    }

    public function isSingleDay(): bool
    {
        return $this->from->isSameDay($this->to);
    }

    /**
     * Label periode berbahasa Indonesia, misalnya "19 – 25 September 2026".
     */
    public function label(): string
    {
        if ($this->isSingleDay()) {
            return $this->from->locale('id')->translatedFormat('d F Y');
        }

        if ($this->from->isSameMonth($this->to)) {
            return $this->from->day.' – '.$this->to->locale('id')->translatedFormat('d F Y');
        }

        return $this->from->locale('id')->translatedFormat('d M Y').' – '.$this->to->locale('id')->translatedFormat('d M Y');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'preset' => $this->preset,
            'label' => $this->label(),
            'date_from' => $this->fromDate(),
            'date_to' => $this->toDate(),
            'timezone' => $this->timezone,
        ];
    }

    private static function parseDate(?string $value, Carbon $fallback): Carbon
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
