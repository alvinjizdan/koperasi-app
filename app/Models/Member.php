<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Member extends Model
{
    use HasFactory;

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
        'joined_at',
        'status',
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
    ];

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
     * Pinjaman milik anggota.
     */
    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }
}
