<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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

    public const TYPE_KUASA_JUAL = 'kuasa_jual';

    public const TYPE_PPJB = 'ppjb';

    public const TYPE_PENGOSONGAN = 'pengosongan';

    /**
     * Daftar 5 jenis dokumen wajib Buyback (Ticket 28C: Kuasa Awal diganti menjadi Kuasa Jual).
     *
     * @var list<string>
     */
    public const REQUIRED_TYPES = [
        self::TYPE_SHM,
        self::TYPE_AKTA_BUYBACK,
        self::TYPE_KUASA_JUAL,
        self::TYPE_PPJB,
        self::TYPE_PENGOSONGAN,
    ];

    /**
     * Seluruh tipe yang diizinkan untuk upload (termasuk legacy 'kuasa_awal' untuk backward compatibility).
     *
     * @var list<string>
     */
    public const ALLOWED_UPLOAD_TYPES = [
        self::TYPE_SHM,
        self::TYPE_AKTA_BUYBACK,
        self::TYPE_KUASA_JUAL,
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
        self::TYPE_KUASA_JUAL => 'Kuasa Jual',
        self::TYPE_KUASA_AWAL => 'Kuasa Jual', // Legacy compatibility display label
        self::TYPE_PPJB => 'PPJB',
        self::TYPE_PENGOSONGAN => 'Pengosongan',
    ];

    /**
     * Normalisasi tipe dokumen ke key canonical baru jika menggunakan legacy key.
     */
    public static function canonicalType(string $type): string
    {
        return $type === self::TYPE_KUASA_AWAL ? self::TYPE_KUASA_JUAL : $type;
    }

    /**
     * Resolusi dokumen dari koleksi bertipe buyback documents dengan fallback backward compatibility.
     * Mengambil dokumen dengan tipe canonical ($type) atau legacy fallback bila belum ada.
     *
     * @param  Collection<int, self>  $documents
     */
    public static function findMatchingDocument(Collection $documents, string $type): ?self
    {
        $canonical = self::canonicalType($type);
        $found = $documents->firstWhere('document_type', $canonical);
        if ($found !== null) {
            return $found;
        }

        if ($canonical === self::TYPE_KUASA_JUAL) {
            return $documents->firstWhere('document_type', self::TYPE_KUASA_AWAL);
        }

        return null;
    }

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
