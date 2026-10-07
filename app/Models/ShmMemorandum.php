<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
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

    public function isSubmitted(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    /**
     * @return array<int, string>
     */
    public static function statuses(): array
    {
        return [self::STATUS_DRAFT, self::STATUS_SUBMITTED];
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
     * Peta status => label. Status tetap status workflow sederhana
     * (draft/submitted), bukan status kelayakan atau keuangan.
     *
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_SUBMITTED => 'Diajukan',
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
