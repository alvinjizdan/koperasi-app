<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Catatan permanen dan terperinci untuk setiap revisi pengajuan memorandum (Ticket CC-04).
 *
 * Mengabadikan seluruh naskah, mitigasi, snapshot debitur, agunan, dokumen buyback,
 * snapshot credit checking, dan content hash pada batas waktu pengajuan (submission boundary).
 *
 * @property int $id
 * @property int $shm_memorandum_id
 * @property int $revision_number
 * @property string $memorandum_type
 * @property int $submitted_by
 * @property string $submitted_by_name
 * @property Carbon $submitted_at
 * @property string|null $narrative_notes
 * @property string|null $risk_mitigation_notes
 * @property Carbon|null $shgb_start_date
 * @property Carbon|null $shgb_end_date
 * @property array $borrower_snapshot
 * @property array $collateral_snapshot
 * @property array|null $buyback_documents
 * @property array $credit_check_snapshot
 * @property array|null $exception_snapshot
 * @property string $template_version
 * @property string $content_hash
 * @property Carbon $created_at
 */
class ShmMemorandumSubmission extends Model
{
    use HasFactory;

    protected $table = 'shm_memorandum_submissions';

    public $timestamps = false;

    protected $fillable = [
        'shm_memorandum_id',
        'revision_number',
        'memorandum_type',
        'submitted_by',
        'submitted_by_name',
        'submitted_at',
        'narrative_notes',
        'risk_mitigation_notes',
        'shgb_start_date',
        'shgb_end_date',
        'borrower_snapshot',
        'collateral_snapshot',
        'buyback_documents',
        'credit_check_snapshot',
        'exception_snapshot',
        'template_version',
        'content_hash',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'revision_number' => 'integer',
            'submitted_at' => 'datetime',
            'shgb_start_date' => 'date',
            'shgb_end_date' => 'date',
            'borrower_snapshot' => 'array',
            'collateral_snapshot' => 'array',
            'buyback_documents' => 'array',
            'credit_check_snapshot' => 'array',
            'exception_snapshot' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Parent memorandum dari revisi ini.
     *
     * @return BelongsTo<ShmMemorandum, $this>
     */
    public function memorandum(): BelongsTo
    {
        return $this->belongsTo(ShmMemorandum::class, 'shm_memorandum_id');
    }

    /**
     * Pengguna yang mengajukan revisi ini.
     *
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * Menghitung hash kanonikal SHA-256 untuk memverifikasi integritas checksum submission artifact.
     *
     * Catatan Integritas: Hash ini bertindak sebagai payload integrity checksum (deteksi perubahan).
     * Bukan tanda tangan digital kriptografis (non-repudiation) karena disimpan berdampingan
     * dengan record tanpa infrastruktur asymmetric key / PKI.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function computeContentHash(array $payload): string
    {
        // Seluruh 15 field material penyusun submission artifact
        $canonicalKeys = [
            'revision_number',
            'memorandum_type',
            'submitted_by',
            'submitted_by_name',
            'submitted_at',
            'narrative_notes',
            'risk_mitigation_notes',
            'shgb_start_date',
            'shgb_end_date',
            'borrower_snapshot',
            'collateral_snapshot',
            'buyback_documents',
            'credit_check_snapshot',
            'exception_snapshot',
            'template_version',
        ];

        $sorted = [];
        foreach ($canonicalKeys as $key) {
            $sorted[$key] = self::canonicalizeValue($payload[$key] ?? null);
        }

        return hash('sha256', json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Rekursif kanonikalisasi nilai untuk menjamin konsistensi key ordering pada array asosiatif / nested JSON.
     */
    private static function canonicalizeValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toISOString();
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/', $value)) {
            try {
                return Carbon::parse($value)->toISOString();
            } catch (\Throwable) {
                // Ignore parse errors, return as is
            }
        }

        if (! is_array($value)) {
            return $value;
        }

        // Cek apakah list (indexed array) atau associative array
        $isList = array_is_list($value);

        if ($isList) {
            // Urutan list elemen dijaga karena semantik urutan bermakna
            return array_map([self::class, 'canonicalizeValue'], $value);
        }

        // Associative array: urutkan berdasarkan key secara deterministik
        ksort($value);

        $normalized = [];
        foreach ($value as $k => $v) {
            $normalized[$k] = self::canonicalizeValue($v);
        }

        return $normalized;
    }

    /**
     * Memeriksa apakah path file tersimpan di salah satu submission revision artifact.
     * Digunakan untuk mencegah penghapusan file fisik dari storage jika masih direferensikan dalam riwayat revisi.
     */
    public static function isFilePathReferenced(string $filePath): bool
    {
        if (trim($filePath) === '') {
            return false;
        }

        // Cari di seluruh rekaman submission revision yang memiliki buyback_documents
        return self::whereNotNull('buyback_documents')
            ->get()
            ->contains(function ($submission) use ($filePath) {
                $docs = $submission->buyback_documents;
                if (! is_array($docs)) {
                    return false;
                }
                foreach ($docs as $doc) {
                    if (is_array($doc) && ($doc['file_path'] ?? null) === $filePath) {
                        return true;
                    }
                }

                return false;
            });
    }
}
