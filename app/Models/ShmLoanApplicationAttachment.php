<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Model lampiran dokumen PDF pengajuan pinjaman SHM.
 *
 * @property int $id
 * @property int $shm_loan_application_id
 * @property string $file_name
 * @property string $file_path
 * @property string $mime_type
 * @property int $file_size
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ShmLoanApplication $application
 */
class ShmLoanApplicationAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'shm_loan_application_id',
        'file_name',
        'file_path',
        'mime_type',
        'file_size',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
        ];
    }

    /**
     * Pengajuan pinjaman SHM pemilik lampiran ini.
     *
     * @return BelongsTo<ShmLoanApplication, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(ShmLoanApplication::class, 'shm_loan_application_id');
    }
}
