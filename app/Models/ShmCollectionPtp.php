<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Record Promise to Pay (PTP) SHM (Ticket 97; kuota & jalur scheduler T99).
 *
 * SATU baris = satu janji bayar konkret (tanggal janji + nominal + catatan)
 * yang dibuat Marketing PEMILIK Collection Case selama case berstatus
 * PROMISE_TO_PAY. PTP adalah entitas persisten TERPISAH dari status workflow
 * case dan dari Collection Activity: aktivitas PROMISE_TO_PAY tetap riwayat
 * komunikasi, sedangkan record ini adalah SUMBER KEBENARAN janji pembayaran
 * yang sesungguhnya.
 *
 * Status (state model minimal T97 — dieja persis sama):
 * - STATUS_OPEN: janji aktif; SATU OPEN per case (partial unique index di
 *   database + guard duplikat di service).
 * - STATUS_FULFILLED: ditandai TERPENUHI secara EKSPLISIT oleh pemilik —
 *   TIDAK PERNAH dihitung otomatis dari pembayaran (pencocokan pembayaran =
 *   aturan masa depan yang masih PENDING).
 * - STATUS_MISSED: dicatat TIDAK DIPENUHI — melalui aksi EKSPLISIT pemilik
 *   (T97) MAUPUN scheduler `collection:ptp-missed` ketika PTP OPEN melewati
 *   promised_date (CONFIRMED T99, idempoten; grace period tetap PENDING).
 * - STATUS_CANCELLED: dibatalkan secara eksplisit oleh pemilik.
 *
 * Transisi manual (tabel `ALLOWED_TRANSITIONS`): OPEN → FULFILLED|MISSED|
 * CANCELLED; ketiga status tujuan TERMINAL tanpa reopen. Jalur scheduler
 * (T99) memakai graph yang sama — SATU tabel transisi untuk semua jalur.
 *
 * Kuota T99 (single source periode): maksimal 3 PTP per periode bulanan
 * berlabuh — anchor = tanggal pembuatan PTP pertama pada case; hitung semua
 * status (OPEN/FULFILLED/MISSED/CANCELLED). Perhitungan periode ada di
 * quotaPeriodFor() (satunya-satunya tempat matematika anchor bulan).
 *
 * YANG SENGAJA TIDAK ADA (bukan kelalaian):
 * - kolom pembayaran/settlement/penalty — tiap domain terpisah dan tidak
 *   pernah disentuh oleh record ini;
 * - automatic FULFILLED dari pembayaran/nominal — pencocokan pembayaran ke
 *   PTP tetap PENDING BUSINESS CONFIRMATION;
 * - reminder/notifikasi WhatsApp-SMS-email — PENDING.
 *
 * @property int $id
 * @property int $shm_collection_case_id
 * @property int $created_by
 * @property Carbon $promised_date
 * @property string $promised_amount
 * @property string $status
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ShmCollectionPtp extends Model
{
    public const STATUS_OPEN = 'OPEN';

    public const STATUS_FULFILLED = 'FULFILLED';

    public const STATUS_MISSED = 'MISSED';

    public const STATUS_CANCELLED = 'CANCELLED';

    /**
     * Status PTP T97 — single source of truth untuk validasi service,
     * Form Request, dan tampilan UI (tanpa nilai tambahan).
     *
     * @var array<int, string>
     */
    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_FULFILLED,
        self::STATUS_MISSED,
        self::STATUS_CANCELLED,
    ];

    /**
     * Tabel transisi status PTP (kontrak T97): hanya OPEN yang dapat
     * bergerak, dan hanya ke tiga status terminal. Status terminal kosong =
     * tanpa reopen.
     *
     * @var array<string, array<int, string>>
     */
    public const ALLOWED_TRANSITIONS = [
        self::STATUS_OPEN => [
            self::STATUS_FULFILLED,
            self::STATUS_MISSED,
            self::STATUS_CANCELLED,
        ],
        self::STATUS_FULFILLED => [],
        self::STATUS_MISSED => [],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * Status terminal — tidak dapat berubah lagi (tanpa reopen).
     *
     * @var array<int, string>
     */
    public const TERMINAL_STATUSES = [
        self::STATUS_FULFILLED,
        self::STATUS_MISSED,
        self::STATUS_CANCELLED,
    ];

    /**
     * Periode quota PTP berlabuh (T99) — SATU-SATUNYA sumber matematika
     * batas periode (guard pembuatan, payload UI, dan scheduler semua
     * memanggil ini).
     *
     * Anchor = tanggal pembuatan PTP pertama pada sebuah Collection Case.
     * Periode ke-N = [anchor + N bulan, anchor + (N+1) bulan − 1 hari]
     * memakai addMonthsNoOverflow (konvensi Carbon/Laravel: anchor 31 Jan →
     * periode berikutnya mulai 28/29 Feb, clamp akhir bulan tanpa overflow
     * ke Maret). Periode saling menyambung — tanpa celah & tanpa tumpang
     * tindih. BUKAN bulan kalender murni, BUKAN rolling 30 hari.
     *
     * Contoh anchor 10 Oktober: periode 0 = 10 Okt–9 Nov, periode 1 =
     * 10 Nov–9 Des, dst.
     *
     * @param  Carbon|null  $anchor  Tanggal PTP pertama pada case (null = case belum punya PTP → tidak ada periode).
     * @param  Carbon  $date  Tanggal yang periodenya dicari (hari ini untuk guard/scheduler).
     * @return array{index: int, start: Carbon, end: Carbon}|null
     */
    public static function quotaPeriodFor(?Carbon $anchor, Carbon $date): ?array
    {
        if ($anchor === null) {
            return null;
        }

        $anchor = $anchor->copy()->startOfDay();
        $date = $date->copy()->startOfDay();

        if ($date->lt($anchor)) {
            $date = $anchor;
        }

        $index = ($date->year - $anchor->year) * 12 + ($date->month - $anchor->month);

        // Koreksi maksimal satu langkah untuk clamp akhir bulan (mis. anchor
        // 31 Jan: selisih bulan kasar bisa menunjuk melewati/mundur satu
        // periode nyata yang dihasilkan addMonthsNoOverflow).
        while ($index > 0 && $anchor->copy()->addMonthsNoOverflow($index)->gt($date)) {
            $index--;
        }

        while ($anchor->copy()->addMonthsNoOverflow($index + 1)->lte($date)) {
            $index++;
        }

        return [
            'index' => $index,
            'start' => $anchor->copy()->addMonthsNoOverflow($index),
            'end' => $anchor->copy()->addMonthsNoOverflow($index + 1)->subDay(),
        ];
    }

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'shm_collection_case_id',
        'created_by',
        'promised_date',
        'promised_amount',
        'status',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'promised_date' => 'date',
        ];
    }

    /**
     * Case induk — PTP tidak pernah dibuat di atas pinjaman/case lain.
     */
    public function case(): BelongsTo
    {
        return $this->belongsTo(ShmCollectionCase::class, 'shm_collection_case_id');
    }

    /**
     * Pembuat record: user internal (Marketing pemilik case) dari session —
     * frontend tidak pernah memasok actor.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scope riwayat PTP: record terbaru di atas; `id` sebagai tie-breaker
     * sehingga urutan deterministik.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('id');
    }
}
