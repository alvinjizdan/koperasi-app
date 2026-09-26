<?php

namespace App\Models;

use Database\Factories\ReconciliationRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Header proses rekonsiliasi: satu baris per eksekusi pemeriksaan.
 *
 * Proses rekonsiliasi hanya membaca data sumber (read-only) dan menghasilkan
 * temuan; tidak pernah mengubah saldo maupun transaksi bisnis.
 *
 * @property int $id
 * @property string $run_number
 * @property string $period_start
 * @property string $period_end
 * @property array<int, string> $modules
 * @property int $run_by
 * @property string $status
 * @property int $checked_rows
 * @property int $findings_count
 * @property string|null $summary
 * @property string|null $error_message
 * @property string|null $started_at
 * @property string|null $finished_at
 */
class ReconciliationRun extends Model
{
    /** @use HasFactory<ReconciliationRunFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_RUNNING = 'RUNNING';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_FAILED = 'FAILED';

    public const MODULE_SAVINGS = 'savings';

    public const MODULE_LOANS = 'loans';

    public const MODULE_CASH = 'cash';

    /** @var array<int, string> */
    public const MODULES = [
        self::MODULE_SAVINGS,
        self::MODULE_LOANS,
        self::MODULE_CASH,
    ];

    protected $fillable = [
        'run_number',
        'period_start',
        'period_end',
        'modules',
        'run_by',
        'status',
        'checked_rows',
        'findings_count',
        'summary',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'modules' => 'array',
            'period_start' => 'date',
            'period_end' => 'date',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function runner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by');
    }

    /** @return HasMany<ReconciliationFinding, $this> */
    public function findings(): HasMany
    {
        return $this->hasMany(ReconciliationFinding::class);
    }

    /**
     * Nomor proses unik dengan format REKON-YYYYMMDD-XXXXXX.
     */
    public static function generateRunNumber(): string
    {
        $date = now()->format('Ymd');
        $maxId = (static::max('id') ?? 0) + 1;
        $seq = str_pad((string) $maxId, 6, '0', STR_PAD_LEFT);

        return "REKON-{$date}-{$seq}";
    }
}
