<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Entitas persisten untuk dispensasi & pengecualian Credit Checking (Ticket CC-04).
 *
 * Mendukung Completion Bypass dan FAIL Override dengan pemisahan tegas antara
 * justifikasi internal (INTERNAL_RESTRICTED) dan ringkasan keterbukaan resmi.
 *
 * @property int $id
 * @property string $exception_number
 * @property int $shm_application_id
 * @property string $exception_type
 * @property string $status
 * @property int $authorized_by
 * @property string $authorized_by_name
 * @property Carbon $authorized_at
 * @property string $blocking_condition
 * @property string $internal_justification
 * @property string $official_disclosure_summary
 * @property int|null $consumed_memorandum_id
 * @property Carbon|null $consumed_at
 * @property int|null $revoked_by
 * @property Carbon|null $revoked_at
 * @property string|null $revocation_reason
 * @property int|null $audit_event_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ShmCreditCheckException extends Model
{
    use HasFactory;

    protected $table = 'shm_credit_check_exceptions';

    public const TYPE_COMPLETION_BYPASS = 'completion_bypass';

    public const TYPE_FAIL_OVERRIDE = 'fail_override';

    public const STATUS_AUTHORIZED = 'authorized';

    public const STATUS_CONSUMED = 'consumed';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'exception_number',
        'shm_application_id',
        'exception_type',
        'status',
        'authorized_by',
        'authorized_by_name',
        'authorized_at',
        'blocking_condition',
        'internal_justification',
        'official_disclosure_summary',
        'consumed_memorandum_id',
        'consumed_at',
        'revoked_by',
        'revoked_at',
        'revocation_reason',
        'audit_event_id',
    ];

    protected function casts(): array
    {
        return [
            'authorized_at' => 'datetime',
            'consumed_at' => 'datetime',
            'revoked_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Pengajuan SHM tempat dispensasi ini terikat secara eksklusif.
     *
     * @return BelongsTo<ShmApplication, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(ShmApplication::class, 'shm_application_id');
    }

    /**
     * Otoritas eksekutif yang menyetujui dispensasi (Direktur Utama).
     *
     * @return BelongsTo<User, $this>
     */
    public function authorizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }

    /**
     * Memorandum yang mengonsumsi dispensasi ini.
     *
     * @return BelongsTo<ShmMemorandum, $this>
     */
    public function consumedMemorandum(): BelongsTo
    {
        return $this->belongsTo(ShmMemorandum::class, 'consumed_memorandum_id');
    }

    /**
     * Pengguna yang mencabut dispensasi ini jika ada.
     *
     * @return BelongsTo<User, $this>
     */
    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /**
     * Apakah dispensasi masih aktif dan dapat dikonsumsi?
     * Berlaku maksimum 14 hari kalender sejak otorisasi (CC-07 / CC-09).
     */
    public function isAvailable(): bool
    {
        if ($this->status !== self::STATUS_AUTHORIZED || $this->consumed_at !== null) {
            return false;
        }

        if ($this->authorized_at && $this->authorized_at->addDays(14)->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Apakah dispensasi sudah dikonsumsi oleh memorandum?
     */
    public function isConsumed(): bool
    {
        return $this->status === self::STATUS_CONSUMED;
    }
}
