<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Menyiapkan role bawaan sesuai config/permissions.php.
 * Superadmin dibuat tanpa permission eksplisit: aksesnya berasal dari bypass.
 *
 * Role sistem yang tidak lagi terdaftar pada config AKTIF akan dinonaktifkan
 * (is_active = false), bukan dihapus: baris role, permission, dan relasi
 * role_user tetap utuh agar data historis dan jejak audit tetap dapat
 * ditelusuri. Menjalankan seeder berkali-kali aman dan tidak membuat duplikasi.
 */
class RoleSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $configuredRoles = config('permissions.default_roles', []);

        $superadmin = Role::query()->updateOrCreate(
            ['name' => Role::SYSTEM_SUPERADMIN],
            [
                'display_name' => 'Superadmin',
                'description' => 'Akses penuh ke seluruh modul dan pengaturan sistem.',
                'is_system' => true,
                'is_active' => true,
            ]
        );
        $superadmin->permissions()->detach();

        foreach ($configuredRoles as $name => $definition) {
            $role = Role::query()->updateOrCreate(
                ['name' => $name],
                [
                    'display_name' => $definition['display_name'],
                    'description' => $definition['description'],
                    'is_system' => true,
                    'is_active' => true,
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

        $this->retireUnconfiguredSystemRoles(array_keys($configuredRoles));
    }

    /**
     * Nonaktifkan role sistem yang sudah tidak lagi dipakai.
     *
     * Hanya menyentuh role sistem (is_system = true) sehingga role buatan
     * pengguna tidak pernah dinonaktifkan oleh seeder. Role yang masih
     * dipakai pengguna tetap dibiarkan datanya utuh; nonaktif berarti role
     * tersebut tidak lagi ditawarkan dan tidak dapat ditugaskan lagi.
     *
     * @param  array<int, string>  $activeNames
     */
    private function retireUnconfiguredSystemRoles(array $activeNames): void
    {
        Role::query()
            ->where('is_system', true)
            ->whereNotIn('name', [...$activeNames, Role::SYSTEM_SUPERADMIN])
            ->where('is_active', true)
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);
    }
}
