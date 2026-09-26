<?php

namespace App\Models;

use Database\Factories\ReconciliationFindingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Temuan rekonsiliasi: selisih atau anomali pada data sumber.
 *
 * Temuan tidak pernah mengoreksi data sumber secara otomatis; perbaikan
 * hanya melalui mekanisme koreksi resmi modul terkait (reversal kas,
 * penyesuaian manual oleh pengelola).
 *
 * @property int $id
 * @property int $reconciliation_run_id
 * @property string $finding_type
 * @property string $module
 * @property string|null $object_type
 * @property int|null $object_id
 * @property string|null $object_label
 * @property string|null $expected_value
 * @property string|null $actual_value
 * @property string|null $variance
 * @property string $priority
 * @property string $status
 * @property string $found_at
 */
class ReconciliationFinding extends Model
{
    /** @use HasFactory<ReconciliationFindingFactory> */
    use HasFactory;

    public const TYPE_BALANCE_MISMATCH = 'BALANCE_MISMATCH';

    public const TYPE_DUPLICATE_REFERENCE = 'DUPLICATE_REFERENCE';

    public const TYPE_MISSING_REFERENCE = 'MISSING_REFERENCE';

    public const TYPE_INVALID_STATUS = 'INVALID_STATUS';

    public const TYPE_UNALLOCATED_PAYMENT = 'UNALLOCATED_PAYMENT';

    public const TYPE_CASH_VARIANCE = 'CASH_VARIANCE';

    public const TYPE_INCOMPLETE_DATA = 'INCOMPLETE_DATA';

    public const STATUS_OPEN = 'OPEN';

    public const STATUS_UNDER_REVIEW = 'UNDER_REVIEW';

    public const STATUS_RESOLVED = 'RESOLVED';

    public const STATUS_ACCEPTED_WITH_REASON = 'ACCEPTED_WITH_REASON';

    public const PRIORITY_LOW = 'LOW';

    public const PRIORITY_MEDIUM = 'MEDIUM';

    public const PRIORITY_HIGH = 'HIGH';

    protected $fillable = [
        'reconciliation_run_id',
        'finding_type',
        'module',
        'object_type',
        'object_id',
        'object_label',
        'expected_value',
        'actual_value',
        'variance',
        'priority',
        'status',
        'found_at',
    ];

    protected function casts(): array
    {
        return [
            'expected_value' => 'decimal:2',
            'actual_value' => 'decimal:2',
            'variance' => 'decimal:2',
            'found_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ReconciliationRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ReconciliationRun::class, 'reconciliation_run_id');
    }

    /** @return HasMany<ReconciliationFollowUp, $this> */
    public function followUps(): HasMany
    {
        return $this->hasMany(ReconciliationFollowUp::class)->orderBy('created_at');
    }
}
