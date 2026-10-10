<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persyaratan tambahan jika pinjaman disetujui (Ticket 67, Section 11).
 *
 * Daftar bebas dibuat pengguna — mengikuti pola checklist dokumen SHM
 * existing (ShmDocument) tanpa daftar wajib yang dikunci.
 *
 * @property int $id
 * @property int $shm_loan_application_id
 * @property string $requirement
 * @property bool $is_done
 */
class ShmLoanApplicationRequirement extends Model
{
    protected $fillable = [
        'shm_loan_application_id',
        'requirement',
        'is_done',
    ];

    protected function casts(): array
    {
        return [
            'is_done' => 'boolean',
        ];
    }

    /**
     * Pengajuan pemilik persyaratan ini.
     *
     * @return BelongsTo<ShmLoanApplication, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(ShmLoanApplication::class, 'shm_loan_application_id');
    }
}
