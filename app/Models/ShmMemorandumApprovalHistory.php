<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Catatan permanen audit trail workflow approval memorandum.
 *
 * @property int $id
 * @property int $shm_memorandum_id
 * @property string $action
 * @property string $from_status
 * @property string $to_status
 * @property int $performed_by
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ShmMemorandumApprovalHistory extends Model
{
    use HasFactory;

    protected $table = 'shm_memorandum_approval_histories';

    public const ACTION_SUBMITTED = 'submitted';

    public const ACTION_REVIEW_STARTED = 'review_started';

    public const ACTION_APPROVED = 'approved';

    public const ACTION_REJECTED = 'rejected';

    public const ACTION_RESUBMITTED = 'resubmitted';

    protected $fillable = [
        'shm_memorandum_id',
        'action',
        'from_status',
        'to_status',
        'performed_by',
        'notes',
    ];

    public function memorandum(): BelongsTo
    {
        return $this->belongsTo(ShmMemorandum::class, 'shm_memorandum_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /**
     * Label aksi untuk UI.
     */
    public function actionLabel(): string
    {
        return match ($this->action) {
            self::ACTION_SUBMITTED => 'Diajukan',
            self::ACTION_REVIEW_STARTED => 'Mulai Review',
            self::ACTION_APPROVED => 'Disetujui',
            self::ACTION_REJECTED => 'Ditolak',
            self::ACTION_RESUBMITTED => 'Diajukan Ulang',
            default => $this->action,
        };
    }
}
