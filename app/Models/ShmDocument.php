<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Item checklist kelengkapan dokumen pengajuan SHM.
 *
 * Daftar dokumen yang WAJIB belum ada. Karena itu document_name disimpan
 * bebas dan dibuat oleh pengguna, bukan dari daftar legal yang dikunci.
 *
 * TIDAK ada upload berkas, OCR, atau tanda tangan digital pada ticket ini.
 * Kolom isian hanya mencatat kelengkapan manual beserta catatan pemeriksa.
 *
 * Ticket 53: taxonomy requirement foundation.
 * Konteks requirement dokumen saat ini HANYA dapat diturunkan dari jenis
 * memorandum pengajuan (SHM/SHGB vs Buyback) yang sudah ada sejak T49.
 * Tidak ada daftar dokumen wajib/opsional/kondisional yang dikonfigurasi.
 *
 * @property int $id
 * @property int $shm_application_id
 * @property string $document_name
 * @property string $status
 * @property int|null $checked_by
 * @property Carbon|null $checked_at
 * @property string|null $notes
 */
class ShmDocument extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    protected $fillable = [
        // shm_application_id diisi server dari route model binding.
        'shm_application_id',
        'checked_by',
        'checked_at',
        'document_name',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ShmApplication::class, 'shm_application_id');
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    public function isComplete(): bool
    {
        return $this->status !== self::STATUS_PENDING;
    }

    /**
     * @return array<int, string>
     */
    public static function statuses(): array
    {
        return [self::STATUS_PENDING, self::STATUS_COMPLETE, self::STATUS_NOT_APPLICABLE];
    }

    // -----------------------------------------------------------------
    // Ticket 53: document requirement context (taxonomy foundation).
    //
    // Business rule untuk daftar dokumen wajib/opsional/kondisional masih
    // PENDING CONFIRMATION. Tidak ada daftar dokumen yang digenerate di sini.
    //
    // Satu-satunya sumbu pembeda yang tersedia hari ini adalah jenis proses
    // yang tercatat pada memorandum pengajuan (SHM/SHGB vs Buyback), yaitu
    // konsep yang sudah ada dan memiliki source of truth sendiri di
    // ShmMemorandum. Apabila belum ada memorandum, konteks requirement
    // dianggap "pending" (belum dikonfigurasi), bukan diasumsi.
    // -----------------------------------------------------------------

    /**
     * Konteks requirement dokumen untuk pengajuan ini.
     *
     * Nilai ini BUKAN daftar dokumen wajib. Ini hanya kategori context
     * yang dapat dibedakan hari ini tanpa mengarang business rule.
     *
     * @return array{current: string, note: string}
     */
    public static function requirementContextFor(ShmApplication $application): array
    {
        $memorandum = $application->latestMemorandum;

        if ($memorandum === null || $memorandum->type === null) {
            return [
                'current' => 'pending',
                'note' => 'Konteks requirement dokumen belum dikonfigurasi. Membedakan SHM/SHGB dan Buyback memerlukan memorandum yang tercatat.',
            ];
        }

        return [
            'current' => $memorandum->type,
            'note' => 'Konteks requirement dokumen hari ini hanya dibedakan berdasarkan jenis memorandum (SHM/SHGB vs Buyback). Daftar dokumen wajib masih pending decision.',
        ];
    }
}
