<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riwayat tindak lanjut temuan: catatan append-only atas setiap perubahan
 * status temuan beserta alasan dan pengguna penanggung jawab.
 *
 * @property int $id
 * @property int $reconciliation_finding_id
 * @property int $user_id
 * @property string|null $from_status
 * @property string $to_status
 * @property string $note
 * @property string $created_at
 */
class ReconciliationFollowUp extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'reconciliation_finding_id',
        'user_id',
        'from_status',
        'to_status',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ReconciliationFinding, $this> */
    public function finding(): BelongsTo
    {
        return $this->belongsTo(ReconciliationFinding::class, 'reconciliation_finding_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
