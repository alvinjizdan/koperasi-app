<?php

namespace App\Models;

use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Role pengguna aplikasi. Hanya role dengan is_system=false yang dapat dihapus.
 *
 * Role dengan is_active=false sudah dinonaktifkan (tidak lagi dipakai struktur
 * operasional), tetapi barisnya tetap disimpan agar data historis utuh.
 *
 * @property int $id
 * @property string $name
 * @property string $display_name
 * @property string|null $description
 * @property bool $is_system
 * @property bool $is_active
 */
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    public const SYSTEM_SUPERADMIN = 'superadmin';

    protected $fillable = [
        'name',
        'display_name',
        'description',
        'is_system',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Role yang masih aktif dan boleh ditugaskan ke pengguna.
     *
     * Role nonaktif tetap tersimpan untuk menjaga data historis, tetapi tidak
     * lagi ditawarkan pada halaman pengguna/role dan tidak dapat ditugaskan
     * melalui validasi.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function isSuperadmin(): bool
    {
        return $this->name === self::SYSTEM_SUPERADMIN;
    }
}
