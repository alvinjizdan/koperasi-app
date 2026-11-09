<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Memorandum pengajuan SHM.
 *
 * Tabel ini hanya menyimpan kategori, naskah, dan status pengajuan memorandum.
 * Tidak ada kolom nominal, margin, bunga, bagi hasil, atau formula keuangan
 * apa pun. Seluruh angka keuangan SHM masih PENDING BUSINESS DECISION, sehingga
 * rumus memorandum (termasuk aturan buyback) belum dikunci di sini.
 *
 * @property int $id
 * @property string|null $type
 * @property int $shm_application_id
 * @property int $created_by
 * @property Carbon|null $submitted_at
 * @property string $status
 * @property string|null $notes
 * @property Carbon|null $shgb_start_date
 * @property Carbon|null $shgb_end_date
 * @property int|null $submitted_by
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property Carbon|null $rejected_at
 * @property string|null $rejection_notes
 */
class ShmMemorandum extends Model
{
    use HasFactory;

    /**
     * Nama tabel ditulis eksplisit.
     *
     * Pluralizer Laravel mengubah "memorandum" menjadi "memoranda", sedangkan
     * seluruh tabel lain memakai bentuk jamak biasa. Ditetapkan di sini agar
     * konsisten dan tidak bergantung pada aturan pluralisasi.
     */
    protected $table = 'shm_memorandums';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_APPROVED = 'approved';

    /**
     * Ticket 49: kategori memorandum. Pembeda teknis naskah, bukan kategori
     * legal dan bukan aturan finansial.
     */
    public const TYPE_SHM_SHGB = 'shm_shgb';

    public const TYPE_BUYBACK = 'buyback';

    protected $fillable = [
        // shm_application_id dan created_by diisi server, bukan dari payload.
        'type',
        'shm_application_id',
        'created_by',
        'submitted_at',
        'status',
        'notes',
        'shgb_start_date',
        'shgb_end_date',
        'submitted_by',
        'reviewed_by',
        'reviewed_at',
        'approved_by',
        'approved_at',
        'rejected_at',
        'rejection_notes',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'shgb_start_date' => 'date',
            'shgb_end_date' => 'date',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ShmApplication::class, 'shm_application_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Riwayat persetujuan workflow memorandum (Ticket 8).
     */
    public function approvalHistories(): HasMany
    {
        return $this->hasMany(ShmMemorandumApprovalHistory::class, 'shm_memorandum_id')->orderBy('created_at', 'asc');
    }

    /**
     * Dokumen legalitas Buyback (mengambil dari aplikasi sumber, atau relasi legacy memorandum).
     */
    public function buybackDocuments(): HasMany
    {
        return $this->hasMany(ShmBuybackDocument::class, 'shm_memorandum_id');
    }

    /**
     * Apakah kelima dokumen legalitas buyback sudah lengkap dan valid?
     */
    public function isBuybackDocumentsComplete(): bool
    {
        if ($this->type !== self::TYPE_BUYBACK) {
            return false;
        }

        // Cek pada pengajuan sumber terlebih dahulu (Ticket 14)
        if ($this->application && $this->application->isBuybackDocumentsComplete()) {
            return true;
        }

        $existingTypes = $this->buybackDocuments()->pluck('document_type')->all();

        foreach (ShmBuybackDocument::REQUIRED_TYPES as $requiredType) {
            if (! in_array($requiredType, $existingTypes, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Menghitung rasio dokumen buyback yang sudah diunggah (misal "3/5").
     */
    public function buybackDocumentsRatio(): string
    {
        if ($this->type !== self::TYPE_BUYBACK) {
            return '-';
        }

        // Ambil dari pengajuan sumber jika ada (Ticket 14)
        if ($this->application) {
            $count = $this->application->buybackDocuments()->whereIn('document_type', ShmBuybackDocument::REQUIRED_TYPES)->count();
            if ($count > 0) {
                return "{$count}/".count(ShmBuybackDocument::REQUIRED_TYPES);
            }
        }

        $count = $this->buybackDocuments()->whereIn('document_type', ShmBuybackDocument::REQUIRED_TYPES)->count();

        return "{$count}/".count(ShmBuybackDocument::REQUIRED_TYPES);
    }

    public function isSubmitted(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    public function isUnderReview(): bool
    {
        return $this->status === self::STATUS_UNDER_REVIEW;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * @return array<int, string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_SUBMITTED,
            self::STATUS_UNDER_REVIEW,
            self::STATUS_APPROVED,
        ];
    }

    /**
     * Daftar kategori memorandum yang valid.
     *
     * @return array<int, string>
     */
    public static function types(): array
    {
        return [self::TYPE_SHM_SHGB, self::TYPE_BUYBACK];
    }

    /**
     * Label kategori untuk ditampilkan pada UI.
     */
    public function typeLabel(): string
    {
        return self::typeLabels()[$this->type] ?? '-';
    }

    /**
     * Peta kategori => label.
     *
     * @return array<string, string>
     */
    public static function typeLabels(): array
    {
        return [
            self::TYPE_SHM_SHGB => 'SHM/SHGB',
            self::TYPE_BUYBACK => 'Buyback',
        ];
    }

    /**
     * Peta status => label.
     *
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_SUBMITTED => 'Diajukan',
            self::STATUS_UNDER_REVIEW => 'Sedang Direview',
            self::STATUS_APPROVED => 'Disetujui',
        ];
    }

    /**
     * Label status untuk ditampilkan pada UI.
     */
    public function statusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? $this->status;
    }
}
