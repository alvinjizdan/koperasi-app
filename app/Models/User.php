<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

#[Fillable(['name', 'email', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /**
     * Seluruh permission efektif pengguna (gabungan dari semua role, unik).
     * Superadmin memiliki seluruh permission pada registry.
     *
     * @return Collection<int, Permission>
     */
    public function effectivePermissions(): Collection
    {
        if ($this->isSuperadmin()) {
            return Permission::query()->orderBy('module')->orderBy('action')->get();
        }

        return Permission::query()
            ->whereHas('roles.users', fn ($q) => $q->where('users.id', $this->id))
            ->orderBy('module')
            ->orderBy('action')
            ->get();
    }

    /**
     * Cek satu permission secara efisien tanpa memuat seluruh daftar.
     */
    public function hasPermission(string $name): bool
    {
        // Invariant CC-03A / CC-05: Superadmin adalah administrator teknis,
        // BUKAN otoritas bisnis untuk dispensasi kelayakan kredit/pengecualian pembiayaan.
        if (in_array($name, ['shm.credit_checking.override_fail', 'shm.credit_checking.bypass_completion'], true)) {
            return Permission::query()
                ->where('permissions.name', $name)
                ->whereHas('roles.users', fn ($q) => $q->where('users.id', $this->id))
                ->exists();
        }

        if ($this->isSuperadmin()) {
            return true;
        }

        return Permission::query()
            ->where('permissions.name', $name)
            ->whereHas('roles.users', fn ($q) => $q->where('users.id', $this->id))
            ->exists();
    }

    /**
     * Apakah pengguna memegang role dengan nama tertentu?
     */
    public function hasRole(string $name): bool
    {
        return $this->roles()->where('roles.name', $name)->exists();
    }

    /**
     * Apakah pengguna memegang role Superadmin?
     * Canonical 5-Factor Invariant: roles.id = 1, name = superadmin, entity = shared, is_system = true, is_active = true.
     */
    public function isSuperadmin(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return $this->roles()
            ->where('roles.id', 1)
            ->where('roles.name', Role::SYSTEM_SUPERADMIN)
            ->where('roles.entity', 'shared')
            ->where('roles.is_system', true)
            ->where('roles.is_active', true)
            ->exists();
    }

    /**
     * Apakah akun ini satu-satunya Superadmin aktif? Dipakai untuk mencegah
     * kehilangan akses administratif terakhir secara tidak sengaja.
     */
    public function isLastActiveSuperadmin(): bool
    {
        if (! $this->isSuperadmin() || ! $this->is_active) {
            return false;
        }

        return static::query()
            ->where('is_active', true)
            ->where('id', '!=', $this->id)
            ->whereHas('roles', function ($q) {
                $q->where('roles.id', 1)
                    ->where('roles.name', Role::SYSTEM_SUPERADMIN)
                    ->where('roles.entity', 'shared')
                    ->where('roles.is_system', true)
                    ->where('roles.is_active', true);
            })
            ->doesntExist();
    }
}
