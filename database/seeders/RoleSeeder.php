<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Menyiapkan role bawaan sesuai config/permissions.php.
 * Superadmin dibuat tanpa permission eksplisit: aksesnya berasal dari bypass.
 */
class RoleSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $superadmin = Role::query()->updateOrCreate(
            ['name' => Role::SYSTEM_SUPERADMIN],
            [
                'display_name' => 'Superadmin',
                'description' => 'Akses penuh ke seluruh modul dan pengaturan sistem.',
                'is_system' => true,
            ]
        );
        $superadmin->permissions()->detach();

        foreach (config('permissions.default_roles', []) as $name => $definition) {
            $role = Role::query()->updateOrCreate(
                ['name' => $name],
                [
                    'display_name' => $definition['display_name'],
                    'description' => $definition['description'],
                    'is_system' => true,
                ]
            );

            $permissions = [];

            if (! empty($definition['all_permissions'])) {
                $except = $definition['except'] ?? [];
                $permissions = Permission::query()
                    ->when($except !== [], fn ($q) => $q->whereNotIn('name', $except))
                    ->pluck('id')
                    ->all();
            } else {
                $permissions = Permission::query()
                    ->whereIn('name', $definition['permissions'] ?? [])
                    ->pluck('id')
                    ->all();
            }

            $role->permissions()->sync($permissions);
        }
    }
}
