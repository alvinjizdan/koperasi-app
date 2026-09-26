<?php

namespace App\Services;

/**
 * Pembaca registry permission pada config/permissions.php.
 */
class PermissionRegistry
{
    /**
     * Seluruh modul beserta label dan action-nya.
     *
     * @return array<string, mixed>
     */
    public function modules(): array
    {
        return config('permissions.modules', []);
    }

    /**
     * Seluruh nama permission yang valid ({module}.{action}).
     *
     * @return array<int, string>
     */
    public function allNames(): array
    {
        $names = [];

        foreach ($this->modules() as $module => $definition) {
            foreach (array_keys($definition['actions'] ?? []) as $action) {
                $names[] = "$module.$action";
            }
        }

        return $names;
    }

    /**
     * Apakah nama permission terdaftar?
     */
    public function isValid(string $name): bool
    {
        return in_array($name, $this->allNames(), true);
    }
}
