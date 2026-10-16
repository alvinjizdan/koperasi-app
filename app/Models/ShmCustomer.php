<?php

namespace App\Models;

use Database\Factories\ShmCustomerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Master data Nasabah SHM.
 *
 * Nasabah SHM adalah business domain tersendiri dan BERBEDA dari anggota
 * koperasi (`App\Models\Member`): record Customer dibuat TERPISAH dan hanya
 * MENYIMPAN rujukan `member_id` ke anggota sumber yang sudah ada (T89C) —
 * membuat nasabah tidak pernah membuat/mengubah anggota, dan asosiasi ini
 * tidak dapat diubah lewat form edit (T102).
 *
 * `customer_number` SELALU dibuat server-side (lihat ShmCustomerService)
 * dengan format resmi `NNNNN-ANG` dari sequence domain Nasabah SHM yang
 * INDEPENDEN dari nomor anggota. Model ini tidak pernah menerima nomor
 * tersebut dari payload klien.
 *
 * @property int $id
 * @property int|null $member_id
 * @property string $customer_number
 * @property string $name
 * @property string|null $nik
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address
 * @property string|null $latitude
 * @property string|null $longitude
 * @property bool $is_active
 */
class ShmCustomer extends Model
{
    /** @use HasFactory<ShmCustomerFactory> */
    use HasFactory;

    protected $fillable = [
        'member_id',
        'customer_number',
        'name',
        'nik',
        'phone',
        'email',
        'address',
        'latitude',
        'longitude',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'member_id' => 'integer',
            'is_active' => 'boolean',
            // Desimal agar koordinat tidak bergeser saat disimpan.
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    /**
     * Anggota koperasi yang menjadi sumber data nasabah SHM ini (T89C).
     *
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    /**
     * Seluruh pengajuan SHM milik nasabah ini.
     *
     * @return HasMany<ShmApplication, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(ShmApplication::class);
    }

    /**
     * Record Pinjaman SHM milik nasabah ini (Ticket 64).
     *
     * @return HasMany<ShmLoan, $this>
     */
    public function loans(): HasMany
    {
        return $this->hasMany(ShmLoan::class);
    }

    /**
     * Apakah titik lokasi nasabah sudah terisi?
     *
     * Lokasi boleh kosong; tidak ada koordinat pengganti yang dibuat otomatis.
     */
    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * Hanya nasabah yang masih aktif.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope query untuk filter status aktif/nonaktif.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        if (empty($status) || $status === 'all') {
            return $query;
        }

        return $query->where('is_active', $status === 'active');
    }

    /**
     * Scope query untuk pencarian nasabah.
     *
     * Memakai `like` (bukan `ilike`) supaya konsisten dengan scope pencarian
     * master data lain di aplikasi (Member) dan tetap dapat dijalankan pada
     * driver SQLite yang dipakai test suite.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (empty($search)) {
            return $query;
        }

        $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($search)).'%';

        return $query->where(function (Builder $q) use ($term): void {
            $q->where('name', 'like', $term)
                ->orWhere('customer_number', 'like', $term)
                ->orWhere('nik', 'like', $term)
                ->orWhere('phone', 'like', $term);
        });
    }
}
