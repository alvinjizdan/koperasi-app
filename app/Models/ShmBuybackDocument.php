<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Model Dokumen Legalitas Memorandum Buyback (Ticket 7).
 *
 * @property int $id
 * @property int $shm_memorandum_id
 * @property string $document_type
 * @property string $file_name
 * @property string $file_path
 * @property string $mime_type
 * @property int $file_size
 * @property int $uploaded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ShmMemorandum $memorandum
 * @property-read User $uploader
 */
class ShmBuybackDocument extends Model
{
    use HasFactory;

    public const TYPE_SHM = 'shm';

    public const TYPE_AKTA_BUYBACK = 'akta_buyback';

    public const TYPE_KUASA_AWAL = 'kuasa_awal';

    public const TYPE_PPJB = 'ppjb';

    public const TYPE_PENGOSONGAN = 'pengosongan';

    /**
     * @var list<string>
     */
    public const REQUIRED_TYPES = [
        self::TYPE_SHM,
        self::TYPE_AKTA_BUYBACK,
        self::TYPE_KUASA_AWAL,
        self::TYPE_PPJB,
        self::TYPE_PENGOSONGAN,
    ];

    /**
     * @var array<string, string>
     */
    public const TYPE_LABELS = [
        self::TYPE_SHM => 'SHM',
        self::TYPE_AKTA_BUYBACK => 'Akta Buyback',
        self::TYPE_KUASA_AWAL => 'Kuasa Awal',
        self::TYPE_PPJB => 'PPJB',
        self::TYPE_PENGOSONGAN => 'Pengosongan',
    ];

    protected $fillable = [
        'shm_application_id',
        'shm_memorandum_id',
        'document_type',
        'file_name',
        'file_path',
        'mime_type',
        'file_size',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
        ];
    }

    /**
     * Relasi ke Pengajuan Buyback (Ticket 14).
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(ShmApplication::class, 'shm_application_id');
    }

    /**
     * Relasi ke Memorandum Buyback (Backward compatibility).
     */
    public function memorandum(): BelongsTo
    {
        return $this->belongsTo(ShmMemorandum::class, 'shm_memorandum_id');
    }

    /**
     * Pengguna yang mengunggah dokumen.
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Label tipe dokumen dalam Bahasa Indonesia.
     */
    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->document_type] ?? $this->document_type;
    }
}
