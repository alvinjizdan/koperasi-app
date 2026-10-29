<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Record Transaksi & Audit Trail Koreksi Settlement Pinjaman SHM (T005).
 *
 * Mengimplementasikan Policy B — Current Period Correction:
 * Settlement original yang sudah COMPLETED tidak diedit atau dihapus secara destruktif.
 * Statusnya ditransisikan ke REVERSED, dan record koreksi ini mencatat hubungan
 * langsung antara original settlement dan replacement settlement secara non-destruktif.
 *
 * @property int $id
 * @property int $shm_loan_id
 * @property int $original_settlement_id
 * @property int|null $replacement_settlement_id
 * @property string $reason
 * @property string $status
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ShmSettlementCorrection extends Model
{
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'shm_loan_id',
        'original_settlement_id',
        'replacement_settlement_id',
        'reason',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'shm_loan_id' => 'integer',
            'original_settlement_id' => 'integer',
            'replacement_settlement_id' => 'integer',
            'created_by' => 'integer',
        ];
    }

    /**
     * Pinjaman pemilik transaksi koreksi ini.
     *
     * @return BelongsTo<ShmLoan, $this>
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(ShmLoan::class, 'shm_loan_id');
    }

    /**
     * Settlement original yang dibatalkan / direversi.
     *
     * @return BelongsTo<ShmLoanSettlement, $this>
     */
    public function originalSettlement(): BelongsTo
    {
        return $this->belongsTo(ShmLoanSettlement::class, 'original_settlement_id');
    }

    /**
     * Settlement pengganti yang diterbitkan.
     *
     * @return BelongsTo<ShmLoanSettlement, $this>
     */
    public function replacementSettlement(): BelongsTo
    {
        return $this->belongsTo(ShmLoanSettlement::class, 'replacement_settlement_id');
    }

    /**
     * Pengguna yang mengeksekusi koreksi pelunasan.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
