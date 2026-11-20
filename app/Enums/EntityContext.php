<?php

namespace App\Enums;

enum EntityContext: string
{
    case Mikro = 'mikro';
    case Makro = 'makro';
    case Shared = 'shared';

    /**
     * Entity permissions yang sah dievaluasi di dalam context ini.
     *
     * @return array<int, string>
     */
    public function allowedPermissionEntities(): array
    {
        return match ($this) {
            self::Mikro => [self::Mikro->value, self::Shared->value],
            self::Makro => [self::Makro->value, self::Shared->value],
            self::Shared => [self::Shared->value],
        };
    }

    /**
     * Entity roles yang sah berkontribusi pada permission di dalam context ini.
     *
     * @return array<int, string>
     */
    public function allowedRoleEntities(): array
    {
        return match ($this) {
            self::Mikro => [self::Mikro->value, self::Shared->value],
            self::Makro => [self::Makro->value, self::Shared->value],
            self::Shared => [self::Shared->value],
        };
    }

    /**
     * Cek apakah permission entity tertentu diizinkan dalam context ini.
     */
    public function allowsPermissionEntity(string $entity): bool
    {
        return in_array($entity, $this->allowedPermissionEntities(), true);
    }
}
