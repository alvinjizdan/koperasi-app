<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Record Promise to Pay (PTP) SHM (Ticket 97).
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
 * - STATUS_MISSED: dicatat TIDAK DIPENUHI secara EKSPLISIT oleh pemilik —
 *   TIDAK PERNAH dihitung otomatis dari `promised_date < hari ini`
 *   (grace period & broken-PTP detection = PENDING BUSINESS CONFIRMATION).
 * - STATUS_CANCELLED: dibatalkan secara eksplisit oleh pemilik.
 *
 * Transisi manual (tabel `ALLOWED_TRANSITIONS`): OPEN → FULFILLED|MISSED|
 * CANCELLED; ketiga status tujuan TERMINAL tanpa reopen.
 *
 * YANG SENGAJA TIDAK ADA (bukan kelalaian):
 * - kolom pembayaran/settlement/penalty/escalation reminder — tiap domain
 *   terpisah dan T97 tidak pernah menyentuhnya;
 * - automatic expiry / automatic MISSED / automatic FULFILLED dari tanggal
 *   atau nominal — contract T91 Rule 8 `cannot_guess` + grace period PENDING;
 * - scheduler/reminder/notifikasi WhatsApp-SMS-email — PENDING.
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
