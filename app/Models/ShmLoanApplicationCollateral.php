<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jaminan pinjaman pada sebuah Pengajuan Pinjaman SHM (Ticket 67, Section 5).
 *
 * `collateral_type`, `binding_status` berupa teks bebas karena master
 * resminya belum tersedia (PENDING BUSINESS CONFIRMATION). Tidak ada kolom
 * foto: infrastructure upload storage belum ada (PENDING INFRASTRUCTURE).
 *
 * @property int $id
 * @property int $shm_loan_application_id
 * @property string|null $collateral_date
 * @property string $collateral_type
 * @property string $collateral_name
 * @property string|null $notes
 * @property string|null $estimated_value
 * @property string|null $binding_status
 * @property string|null $binding_date
 * @property string|null $rights_value
 */
class ShmLoanApplicationCollateral extends Model
{
    protected $fillable = [
        'shm_loan_application_id',
        'collateral_date',
        'collateral_type',
        'collateral_name',
        'notes',
        'estimated_value',
        'binding_status',
        'binding_date',
        'rights_value',
    ];

    protected function casts(): array
    {
        return [
            'collateral_date' => 'date',
            'binding_date' => 'date',
            'estimated_value' => 'decimal:2',
            'rights_value' => 'decimal:2',
        ];
    }

    /**
     * Pengajuan pemilik jaminan ini.
     *
     * @return BelongsTo<ShmLoanApplication, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(ShmLoanApplication::class, 'shm_loan_application_id');
    }
}
