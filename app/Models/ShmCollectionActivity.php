<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Record Collection Activity SHM (Ticket 93).
 *
 * SATU baris = satu aktivitas operasional yang dicatat Marketing pemilik
 * Collection Case (apa yang benar-benar dilakukan saat menangani case).
 * Aktivitas adalah RIWAYAT TULIS-SAJI yang melekat pada case (TIDAK dapat
 * berdiri sendiri di atas pinjaman) dan TIDAK pernah memiliki efek samping
 * lifecycle: status case, Promise-to-Pay, pembayaran, settlement, maupun
 * status pinjaman tidak pernah berubah karena pencatatan aktivitas.
 *
 * Nilai kontraktual (T91 Rule 6/7 — dieja persis sama):
 * - TYPES: phone_call, visit, follow_up, other.
 * - RESULTS: CONTACTED, NOT_REACHED, PROMISE_TO_PAY, PAID, UNABLE_TO_PAY,
 *   OTHER — PROMISE_TO_PAY/PAID/UNABLE_TO_PAY di sini HANYA hasil laporan
 *   historis; domain PTP/payment/escalation berada di ticket terpisah.
 *
 * YANG SENGAJA TIDAK ADA (bukan kelalaian):
 * - kolom WhatsApp/SMS/email/koordinat/attachment/PTP/escalation/payment —
 *   PTP kini berupa entity terpisah (`shm_collection_ptps`, T97); kolom
 *   lainnya tetap di luar scope dan escalation otomatis masih PENDING
 *   BUSINESS CONFIRMATION;
 * - automatic next_action scheduling — `next_action` murni teks operasional;
 * - batas waktu aktivitas (backdate/masa depan) — contract tidak mendefinisikan,
 *   sehingga TIDAK ada kebijakan tanggal yang dikarang di T93.
 *
 * @property int $id
 * @property int $shm_collection_case_id
 * @property int $user_id
 * @property string $activity_type
 * @property string $result
 * @property Carbon $activity_at
 * @property string|null $notes
 * @property string|null $next_action
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ShmCollectionActivity extends Model
{
    public const TYPE_PHONE_CALL = 'phone_call';

    public const TYPE_VISIT = 'visit';

    public const TYPE_FOLLOW_UP = 'follow_up';

    public const TYPE_OTHER = 'other';

    public const RESULT_CONTACTED = 'CONTACTED';

    public const RESULT_NOT_REACHED = 'NOT_REACHED';

    public const RESULT_PROMISE_TO_PAY = 'PROMISE_TO_PAY';

    public const RESULT_PAID = 'PAID';

    public const RESULT_UNABLE_TO_PAY = 'UNABLE_TO_PAY';

    public const RESULT_OTHER = 'OTHER';

    /**
     * Tipe aktivitas contract T91 Rule 6 — single source of truth untuk
     * validasi service, Form Request, dan opsi UI (tanpa nilai tambahan).
     *
     * @var array<int, string>
     */
    public const TYPES = [
        self::TYPE_PHONE_CALL,
        self::TYPE_VISIT,
        self::TYPE_FOLLOW_UP,
        self::TYPE_OTHER,
    ];

    /**
     * Hasil aktivitas contract T91 Rule 7 — single source of truth.
     *
     * @var array<int, string>
     */
    public const RESULTS = [
        self::RESULT_CONTACTED,
        self::RESULT_NOT_REACHED,
        self::RESULT_PROMISE_TO_PAY,
        self::RESULT_PAID,
        self::RESULT_UNABLE_TO_PAY,
        self::RESULT_OTHER,
    ];

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'shm_collection_case_id',
        'user_id',
        'activity_type',
        'result',
        'activity_at',
        'notes',
        'next_action',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activity_at' => 'datetime',
        ];
    }

    /**
     * Case induk — aktivitas tidak pernah dibuat langsung di atas pinjaman.
     */
    public function case(): BelongsTo
    {
        return $this->belongsTo(ShmCollectionCase::class, 'shm_collection_case_id');
    }

    /**
     * Actor: user internal (Marketing pemilik case) yang mencatat aktivitas
     * — mengikuti pola semantik relasi model (marketing/creator), bukan nama
     * kolom.
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Scope riwayat operasional: aktivitas terbaru di atas; `id` sebagai
     * tie-breaker sehingga urutan deterministik pada activity_at kembar.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('activity_at')->orderByDesc('id');
    }
}
