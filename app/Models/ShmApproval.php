<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Keputusan approval pengajuan SHM, single stage untuk MVP.
 *
 * Multi-level approval adalah PENDING BUSINESS DECISION dan tidak
 * diimplementasikan. Batas kewenangan, siapa yang berhak menyetujui, dan
 * syarat dokumen sebelum approve juga belum diputuskan KSP.
 *
 * decided_by diisi dari pengguna yang benar-benar memutuskan, bukan dari
 * payload klien.
 *
 * @property int $id
 * @property int $shm_application_id
 * @property int|null $decided_by
 * @property string $status
 * @property Carbon|null $decided_at
 * @property string|null $notes
 */
class ShmApproval extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        // decided_by berasal dari pengguna yang memutuskan, bukan dari payload.
        'shm_application_id',
        'decided_by',
        'decided_at',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ShmApplication::class, 'shm_application_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isDecided(): bool
    {
        return $this->status !== self::STATUS_PENDING;
    }

    /**
     * @return array<int, string>
     */
    public static function statuses(): array
    {
        return [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED];
    }
}
