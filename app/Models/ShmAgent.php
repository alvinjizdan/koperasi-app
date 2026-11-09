<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Model Master Agen domain SHM / BSM Makro.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $nik
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ShmAgent extends Model
{
    use HasFactory;

    protected $table = 'shm_agents';

    protected $fillable = [
        'code',
        'name',
        'nik',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Scope untuk agen aktif saja.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope pencarian berdasarkan kode, nama, atau NIK.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term): void {
            $q->where('code', 'ilike', "%{$term}%")
                ->orWhere('name', 'ilike', "%{$term}%")
                ->orWhere('nik', 'like', "%{$term}%");
        });
    }

    /**
     * Scope filter status: all, active, inactive.
     */
    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        return match ($status) {
            'active' => $query->where('is_active', true),
            'inactive' => $query->where('is_active', false),
            default => $query,
        };
    }

    /**
     * Relasi ke pengajuan pinjaman SHM.
     */
    public function loanApplications(): HasMany
    {
        return $this->hasMany(ShmLoanApplication::class, 'agent_id');
    }
}
