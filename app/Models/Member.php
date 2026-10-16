<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Anggota koperasi.
 *
 * `member_number` (kode anggota) dibuat server-side dengan format `NNNNN-ANG`
 * (mis. `00001-ANG`) dari sequence `members_code_seq` — independen dari
 * sequence Nasabah SHM (T102-FIX). Kode legacy berformat lain (mis.
 * `ANG-0001`) dibiarkan utuh dan tidak dihitung sequence. Kode immutable
 * setelah tercipta.
 */
class Member extends Model
{
    use HasFactory;

    /**
     * Percobaan maksimal menghasilkan kode anggota saat tabrakan unique
     * constraint (safety net sequence).
     */
    private const NUMBER_ATTEMPTS = 5;

    /**
     * Nama tabel database.
     *
     * @var string
     */
    protected $table = 'members';

    /**
     * Kolom yang dapat diisi secara mass-assignment.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'member_number',
        'full_name',
        'identity_number',
        'phone',
        'address',
        'latitude',
        'longitude',
        'joined_at',
        'status',
    ];

    /**
     * Titik lokasi tidak ikut terkirim pada respons biasa.
     *
     * Data lokasi dilindungi: koordinat hanya dikirim melalui prop terpisah
     * yang hanya diberikan kepada pengguna yang memiliki permission
     * `marketing.member_location.view`. Tanpa ini, setiap pengguna yang boleh
     * membuka detail anggota (kasir, pengawas, admin) ikut menerima koordinat.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'latitude',
        'longitude',
    ];

    /**
     * Tipe data penyesuaian atribut (casts).
     *
     * @var array<string, string>
     */
    protected $casts = [
        'joined_at' => 'date:Y-m-d',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        // Desimal, bukan float: koordinat tidak boleh bergeser saat disimpan.
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    /**
     * Apakah titik lokasi anggota sudah terisi?
     *
     * Lokasi boleh kosong; tidak ada koordinat pengganti yang dibuat otomatis.
     */
    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * Hasilkan kode anggota baru `NNNNN-ANG` (mis. `00001-ANG`).
     *
     * PostgreSQL memakai sequence `members_code_seq` (aman untuk paralel,
     * independen dari `shm_customers_code_seq`). Driver lain (sqlite pada
     * test) memakai fallback: kode format baru tertinggi + 1. Unique
     * constraint `members.member_number` tetap menjadi safety net.
     */
    public static function generateMemberNumber(): string
    {
        $sequence = null;

        if (DB::getDriverName() === 'pgsql') {
            try {
                $result = DB::selectOne("SELECT nextval('members_code_seq') AS sequence");
                $sequence = $result->sequence !== null ? (int) $result->sequence : null;
            } catch (\Throwable) {
                $sequence = null;
            }
        }

        if ($sequence === null || $sequence < 1) {
            $sequence = static::highestNewFormatSequence() + 1;
        }

        return str_pad((string) $sequence, 5, '0', STR_PAD_LEFT).'-ANG';
    }

    /**
     * Simpan anggota baru dengan kode hasil server (`NNNNN-ANG`).
     *
     * Tabrakan unique constraint `member_number` (safety net) diulang dengan
     * kode berikutnya hingga NUMBER_ATTEMPTS; galat lain langsung dilempar.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function createWithGeneratedNumber(array $attributes): static
    {
        for ($attempt = 1; $attempt <= self::NUMBER_ATTEMPTS; $attempt++) {
            try {
                return static::create($attributes + ['member_number' => static::generateMemberNumber()]);
            } catch (QueryException $exception) {
                $isMemberNumberCollision = str_contains($exception->getMessage(), 'member_number');

                if (! $isMemberNumberCollision || $attempt === self::NUMBER_ATTEMPTS) {
                    throw $exception;
                }
            }
        }

        throw new \RuntimeException('Kode anggota tidak dapat dibuat setelah beberapa percobaan.');
    }

    /**
     * Kode format baru tertinggi yang sudah ada (tanpa sequence), untuk
     * fallback driver non-PostgreSQL dan pengaman sequence yang belum
     * dimulai dari nilai aman.
     */
    private static function highestNewFormatSequence(): int
    {
        return (int) static::query()
            ->pluck('member_number')
            ->map(fn (string $number): ?int => preg_match('/^(\d{5})-ANG$/', $number, $matches) === 1 ? (int) $matches[1] : null)
            ->filter()
            ->max();
    }

    /**
     * Hanya anggota yang titik lokasinya sudah terisi.
     */
    public function scopeWithLocation(Builder $query): Builder
    {
        return $query->whereNotNull('latitude')->whereNotNull('longitude');
    }

    /**
     * Scope query untuk pencarian anggota.
     */
    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (empty($search)) {
            return $query;
        }

        $term = '%'.trim($search).'%';

        return $query->where(function (Builder $q) use ($term) {
            $q->where('full_name', 'like', $term)
                ->orWhere('member_number', 'like', $term)
                ->orWhere('identity_number', 'like', $term)
                ->orWhere('phone', 'like', $term);
        });
    }

    /**
     * Scope query untuk filter status anggota.
     */
    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        if (empty($status) || $status === 'all') {
            return $query;
        }

        return $query->where('status', $status);
    }

    /**
     * Rekening simpanan milik anggota.
     */
    public function savingsAccounts(): HasMany
    {
        return $this->hasMany(SavingsAccount::class);
    }

    /**
     * Nasabah SHM yang bersumber dari anggota ini (T89C).
     */
    public function shmCustomers(): HasMany
    {
        return $this->hasMany(ShmCustomer::class, 'member_id');
    }

    /**
     * Pinjaman milik anggota.
     */
    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }
}

/*
 * Ticket 71: relasi marketingActivities() DIHAPUS. Aktivitas/kunjungan
 * Marketing kini berada penuh di domain SHM dan menunjuk ke `shm_customers`
 * (shm_customer_id), sehingga anggota koperasi tidak lagi memiliki relasi ke
 * marketing_activities. Relasi tersebut tidak dipakai di mana pun dan akan
 * rusak setelah kolom member_id dihapus.
 */
