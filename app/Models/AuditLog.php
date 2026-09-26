<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entri audit trail: siapa melakukan apa terhadap objek apa dan kapan.
 *
 * Kolom changes hanya memuat ringkasan yang aman (old/new untuk field terpilih);
 * password, token, dan kredensial tidak pernah dicatat.
 *
 * @property int $id
 * @property int|null $actor_id
 * @property string $action
 * @property string $object_type
 * @property int|null $object_id
 * @property string|null $object_label
 * @property string|null $description
 * @property array|null $changes
 * @property string|null $ip_address
 * @property string $created_at
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_id',
        'action',
        'object_type',
        'object_id',
        'object_label',
        'description',
        'changes',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
