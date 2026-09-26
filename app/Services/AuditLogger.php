<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Audit trail terpusat untuk perubahan penting lintas modul.
 *
 * Nilai sensitif (password, token, kredensial) tidak pernah dicatat: field
 * tersebut disaring sebelum ringkasan perubahan disimpan.
 */
class AuditLogger
{
    /**
     * Field yang tidak boleh masuk ke audit log dalam bentuk apa pun.
     *
     * @var array<int, string>
     */
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'remember_token',
        'idempotency_key',
    ];

    /**
     * Catat peristiwa audit.
     *
     * @param  array<string, mixed>|null  $changes
     */
    public static function log(
        string $action,
        string $objectType,
        ?int $objectId = null,
        ?string $objectLabel = null,
        ?string $description = null,
        ?array $changes = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'actor_id' => Auth::id(),
            'action' => $action,
            'object_type' => $objectType,
            'object_id' => $objectId,
            'object_label' => $objectLabel !== null ? mb_substr($objectLabel, 0, 150) : null,
            'description' => $description,
            'changes' => $changes !== null ? self::sanitize($changes) : null,
            'ip_address' => Request::ip(),
        ]);
    }

    /**
     * Catat perubahan field pada model dengan struktur old/new.
     *
     * @param  array<int, string>  $trackedFields
     */
    public static function logChanges(Model $model, string $action, array $trackedFields, ?string $description = null): AuditLog
    {
        $changes = [];

        foreach ($trackedFields as $field) {
            if ($model->wasChanged($field)) {
                $changes[$field] = [
                    'old' => self::formatValue($model->getOriginal($field)),
                    'new' => self::formatValue($model->getAttribute($field)),
                ];
            }
        }

        return self::log(
            $action,
            class_basename($model),
            $model->getKey(),
            self::labelFor($model),
            $description,
            $changes === [] ? null : $changes
        );
    }

    /**
     * Buang field sensitif dan batasi kedalaman struktur perubahan.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private static function sanitize(array $changes): array
    {
        return collect($changes)
            ->reject(fn ($value, string $key) => in_array(strtolower($key), self::SENSITIVE_KEYS, true))
            ->map(fn ($value) => is_array($value) ? self::sanitize($value) : $value)
            ->all();
    }

    private static function formatValue(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 'aktif' : 'nonaktif';
        }

        return $value;
    }

    private static function labelFor(Model $model): ?string
    {
        return match (true) {
            isset($model->name) => (string) $model->name,
            isset($model->full_name) => (string) $model->full_name,
            isset($model->display_name) => (string) $model->display_name,
            isset($model->key) => (string) $model->key,
            default => null,
        };
    }
}
