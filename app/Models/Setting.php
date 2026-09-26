<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pengaturan aplikasi (profil koperasi, operasional) sebagai key-value.
 * Nilai default diindikasikan pada config/settings.php dan disemai oleh seeder.
 *
 * @property int $id
 * @property string $key
 * @property string|null $value
 * @property string $group
 */
class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'group',
    ];
}
