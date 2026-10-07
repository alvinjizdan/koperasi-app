<?php

namespace App\Models;

use App\Enums\ShmApplicationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Satu pengajuan SHM/SHGB.
 *
 * Tabel ini adalah proses PRA-pembiayaan dan sengaja TIDAK terhubung ke tabel
 * loans. Pembiayaan SHM menjadi record pinjaman adalah ticket berikutnya,
 * bukan bagian dari foundation ini.
 *
 * marketing_id, submitted_at, approved_at, rejected_at, disbursed_at, dan
 * completed_at SELALU berasal dari server. Model ini tidak pernah menerima
 * nilai-nilai tersebut dari payload klien.
 *
 * @property int $id
 * @property string $application_number
 * @property int $shm_customer_id
 * @property int $marketing_id
 * @property ShmApplicationStatus $status
 * @property Carbon|null $submitted_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $rejected_at
 * @property Carbon|null $disbursed_at
 * @property Carbon|null $completed_at
 * @property string|null $notes
 */
class ShmApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'application_number',
        'shm_customer_id',
        'marketing_id',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => ShmApplicationStatus::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'disbursed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Nasabah SHM pemilik pengajuan ini.
     *
     * Relasi ini menunjuk ke master data nasabah sendiri, BUKAN ke anggota
     * koperasi. Nasabah SHM tidak dianggap anggota koperasi.
     *
     * @return BelongsTo<ShmCustomer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(ShmCustomer::class, 'shm_customer_id');
    }

    public function marketing(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marketing_id');
    }

    public function properties(): HasMany
    {
        return $this->hasMany(ShmProperty::class);
    }

    public function creditChecks(): HasMany
    {
        return $this->hasMany(ShmCreditCheck::class);
    }

    public function memorandums(): HasMany
    {
        return $this->hasMany(ShmMemorandum::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(ShmApproval::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ShmDocument::class);
    }

    /**
     * Aktivitas Marketing yang menghasilkan pengajuan ini.
     *
     * Relasi ini opsional: Marketing boleh membuat pengajuan tanpa aktivitas
     * kunjungan, sehingga kolomnya boleh kosong.
     */
    public function marketingActivity(): BelongsTo
    {
        return $this->belongsTo(MarketingActivity::class);
    }

    /**
     * Credit check terakhir yang tercatat, atau null bila belum ada.
     */
    public function latestCreditCheck(): HasOne
    {
        return $this->hasOne(ShmCreditCheck::class)->latestOfMany();
    }

    /**
     * Memorandum terakhir yang tercatat, atau null bila belum ada.
     */
    public function latestMemorandum(): HasOne
    {
        return $this->hasOne(ShmMemorandum::class)->latestOfMany();
    }

    /**
     * Keputusan approval terakhir, atau null bila belum ada.
     */
    public function latestApproval(): HasOne
    {
        return $this->hasOne(ShmApproval::class)->latestOfMany();
    }

    /**
     * Batasi query pada Marketing tertentu.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForMarketing(Builder $query, int $marketingId): Builder
    {
        return $query->where('marketing_id', $marketingId);
    }

    /**
     * Nama nasabah untuk ditampilkan.
     *
     * Pengajuan selalu menunjuk ke satu Nasabah SHM, sehingga tidak ada lagi
     * konsep "calon nasabah" tanpa entitas.
     */
    public function customerLabel(): string
    {
        return $this->customer?->name ?? '-';
    }

    /**
     * Apakah pengajuan ini sudah keluar dari tahap draft.
     */
    public function isEditable(): bool
    {
        return $this->status === ShmApplicationStatus::Draft;
    }

    /**
     * Kelengkapan dokumen: seluruh item berstatus complete atau not_applicable.
     *
     * Belum ada aturan KSP yang mewajibkan daftar dokumen tertentu, sehingga
     * method ini hanya merangkum checklist yang benar-benar ada.
     */
    public function documentsComplete(): bool
    {
        $documents = $this->documents;

        if ($documents->isEmpty()) {
            return false;
        }

        return $documents->every(
            static fn (ShmDocument $document): bool => $document->status !== ShmDocument::STATUS_PENDING
        );
    }

    /**
     * Ringkasan administratif checklist dokumen.
     *
     * PENTING: ringkasan ini murni informasi. Kelengkapan dokumen TIDAK
     * otomatis menyebabkan pengajuan disetujui. Approval tetap harus melalui
     * tahap approval dan transition yang sudah ada.
     *
     * @return array{total: int, complete: int, pending: int, is_complete: bool}
     */
    public function documentsSummary(): array
    {
        $documents = $this->documents;
        $complete = $documents->filter(
            static fn (ShmDocument $document): bool => $document->status !== ShmDocument::STATUS_PENDING
        )->count();

        return [
            'total' => $documents->count(),
            'complete' => $complete,
            'pending' => $documents->count() - $complete,
            'is_complete' => $documents->isNotEmpty() && $complete === $documents->count(),
        ];
    }

    /**
     * Nasabah SHM aktif untuk dipilih pada form pengajuan.
     *
     * Master nasabah bersifat bersama: Marketing boleh memilih nasabah mana
     * pun yang sudah terdaftar, bukan hanya yang ia buat sendiri.
     *
     * @return Collection<int, ShmCustomer>
     */
    public function selectableCustomers()
    {
        return ShmCustomer::query()
            ->active()
            ->orderBy('name')
            ->get(['id', 'customer_number', 'name']);
    }

    /**
     * Aktivitas Marketing milik pengguna tertentu yang boleh dihubungkan.
     *
     * Dipakai agar Marketing hanya dapat memilih aktivitas miliknya sendiri.
     * Admin yang memegang shm.view_all boleh melihat seluruh aktivitas.
     *
     * @return Collection<int, MarketingActivity>
     */
    public function selectableActivities(?int $ownerId = null)
    {
        return MarketingActivity::query()
            ->when($ownerId !== null, static fn ($query) => $query->where('marketing_id', $ownerId))
            ->orderByDesc('checked_in_at')
            ->limit(50)
            ->get();
    }
}
