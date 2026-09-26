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
        foreach (app(PermissionRegistry::class)->modules() as $module => $definition) {
            foreach ($definition['actions'] ?? [] as $action => $description) {
                Permission::query()->updateOrCreate(
                    ['name' => $module.'.'.$action],
                    [
                        'module' => $module,
                        'action' => $action,
                        'display_name' => $description,
                    ]
                );
            }
        }
    }
}
