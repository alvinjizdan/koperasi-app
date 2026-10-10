<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Barang agunan kredit pada Pengajuan Pinjaman SHM (Ticket 67, Section 4).
 *
 * Hanya jenis barang, nomor, tahun, dan nilai perkiraan — sesuai contract
 * UI T66/T67 tanpa business rule tambahan.
 *
 * @property int $id
 * @property int $shm_loan_application_id
 * @property string $asset_type
 * @property string|null $asset_number
 * @property string|null $asset_year
 * @property string|null $estimated_value
 */
class ShmLoanApplicationAsset extends Model
{
    protected $fillable = [
        'shm_loan_application_id',
        'asset_type',
        'asset_number',
        'asset_year',
        'estimated_value',
    ];

    protected function casts(): array
    {
        return [
            'estimated_value' => 'decimal:2',
        ];
    }

    /**
     * Pengajuan pemilik barang agunan ini.
     *
     * @return BelongsTo<ShmLoanApplication, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(ShmLoanApplication::class, 'shm_loan_application_id');
    }
}
