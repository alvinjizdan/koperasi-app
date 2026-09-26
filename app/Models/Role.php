<?php

namespace App\Models;

use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Role pengguna aplikasi. Hanya role dengan is_system=false yang dapat dihapus.
 *
 * @property int $id
 * @property string $name
 * @property string $display_name
 * @property string|null $description
 * @property bool $is_system
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
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
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
