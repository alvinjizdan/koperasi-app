<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Record Collection Case SHM (Ticket 92).
 *
 * SATU case merepresentasikan proses penanganan overdue terhadap SATU pinjaman
 * SHM. Status case adalah state OPERASIONAL TERPISAH: pembuatan/perubahan case
 * tidak pernah menyentuh `shm_loans.status`, status angsuran, payment, maupun
 * settlement.
 *
 * Status kontraktual (lifecycle T91 — contract values, dieja persis sama):
 * - STATUS_OPEN: pinjaman eligible Collection, belum ada handling aktif.
 * - STATUS_IN_PROGRESS: Marketing sedang menangani/follow-up.
 * - STATUS_PROMISE_TO_PAY: janji pembayaran customer sudah dicatat.
 * - STATUS_ESCALATED: butuh penanganan Branch Manager / President Director.
 * - STATUS_RESOLVED: kewajiban target Collection selesai — case tertutup.
 *
 * Definisi "active case" (baseline T92): status SELAIN RESOLVED. Case
 * RESOLVED permanen (TIDAK dapat reopen); pinjaman yang kembali memenuhi
 * eligibility membuka case BARU sebagai baris terpisah, sehingga riwayat case
 * RESOLVED tetap utuh berdampingan dengan case aktif berikutnya.
 *
 * YANG SENGAJA TIDAK ADA (bukan kelalaian):
 * - `collector_id` / role Collector — collection awal ditangani Marketing;
 * - kolom eskalasi (escalation_to/escalation_reason), prioritas, PTP
 *   date/amount, cadence, next_follow_up_at, penalty, notification state —
 *   masing-masing memiliki ticket terpisah;
 * - alasan transisi — disimpan pada audit (`AuditLog.changes`), bukan kolom;
 * - transisi keluar dari RESOLVED — reopening tidak diizinkan di T92.
 *
 * @property int $id
 * @property int $shm_loan_id
 * @property int $marketing_id
 * @property string $status
 * @property Carbon $opened_at
 * @property Carbon|null $resolved_at
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ShmCollectionCase extends Model
{
    public const STATUS_OPEN = 'OPEN';

    public const STATUS_IN_PROGRESS = 'IN_PROGRESS';

    public const STATUS_PROMISE_TO_PAY = 'PROMISE_TO_PAY';

    public const STATUS_ESCALATED = 'ESCALATED';

    public const STATUS_RESOLVED = 'RESOLVED';

    /**
     * Tabel transisi valid — baseline T92 dari contract T91 (Rule 4:
     * `transition_table` ditentukan pada ticket implementasi).
     *
     * RESOLVED tidak memiliki transisi keluar (tanpa reopening di T92).
     * OPEN → RESOLVED hanya melalui invokasi service EKSPLISIT dengan alasan
     * berbasis state pembayaran — T92 tidak membuat automatic resolution.
     *
     * @var array<string, array<int, string>>
     */
    public const ALLOWED_TRANSITIONS = [
        self::STATUS_OPEN => [self::STATUS_IN_PROGRESS, self::STATUS_RESOLVED],
        self::STATUS_IN_PROGRESS => [self::STATUS_PROMISE_TO_PAY, self::STATUS_ESCALATED, self::STATUS_RESOLVED],
        self::STATUS_PROMISE_TO_PAY => [self::STATUS_IN_PROGRESS, self::STATUS_ESCALATED, self::STATUS_RESOLVED],
        self::STATUS_ESCALATED => [self::STATUS_IN_PROGRESS, self::STATUS_RESOLVED],
        self::STATUS_RESOLVED => [],
    ];

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'shm_loan_id',
        'marketing_id',
        'status',
        'opened_at',
        'resolved_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(ShmLoan::class, 'shm_loan_id');
    }

    /**
     * Initial owner case — Marketing (kontrak T91 Rule 1; tanpa Collector).
     */
    public function marketing(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marketing_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Riwayat aktivitas operasional pada case ini (Ticket 93) — append-only
     * dengan FK RESTRICT (riwayat tidak ikut terhapus bersama case).
     *
     * @return HasMany<ShmCollectionActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(ShmCollectionActivity::class, 'shm_collection_case_id');
    }

    /**
     * Riwayat record Promise to Pay pada case ini (Ticket 97) — append-only
     * dengan FK RESTRICT; multiple PTP per case disimpan sebagai riwayat
     * (hanya SATU berstatus OPEN berkat partial unique index).
     *
     * @return HasMany<ShmCollectionPtp, $this>
     */
    public function ptps(): HasMany
    {
        return $this->hasMany(ShmCollectionPtp::class, 'shm_collection_case_id');
    }

    /**
     * Scope case aktif: status selain RESOLVED — definisi yang sama dengan
     * partial unique index `shm_collection_cases_active_unique`.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', '<>', self::STATUS_RESOLVED);
    }

    /**
     * Apakah case masih aktif (belum RESOLVED)?
     */
    public function isActive(): bool
    {
        return $this->status !== self::STATUS_RESOLVED;
    }
}
