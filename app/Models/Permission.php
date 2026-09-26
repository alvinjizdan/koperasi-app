<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Permission aplikasi berbasis modul + aksi (mis. anggota.view, kasir.open_session).
 * Master permission diindeks pada config/permissions.php dan disinkronkan seeder.
 *
 * @property int $id
 * @property string $name
 * @property string $module
 * @property string $action
 * @property string $display_name
 */
class Permission extends Model
{
    protected $fillable = [
        'name',
        'module',
        'action',
        'display_name',
    ];

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}
