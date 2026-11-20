<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Services\PermissionRegistry;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Menyelaraskan tabel permissions dengan registry pada config/permissions.php.
 * Aman dijalankan berulang; permission tidak dipakai tidak dihapus otomatis.
 */
class PermissionSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $registry = app(PermissionRegistry::class);

        foreach ($registry->modules() as $module => $definition) {
            foreach ($definition['actions'] ?? [] as $action => $description) {
                $name = $module.'.'.$action;
                Permission::query()->updateOrCreate(
                    ['name' => $name],
                    [
                        'module' => $module,
                        'action' => $action,
                        'display_name' => $description,
                        'entity' => $registry->entityFor($name),
                    ]
                );
            }
        }
    }
}
