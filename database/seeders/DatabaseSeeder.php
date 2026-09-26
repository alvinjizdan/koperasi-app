<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            SettingsSeeder::class,
        ]);

        // Akun Superadmin awal hanya dibuat bila belum ada sama sekali.
        $superadmin = User::query()->firstOrCreate(
            ['email' => 'superadmin@ksp.local'],
            [
                'name' => 'Superadmin Koperasi',
                'password' => 'password',
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        $superadminRole = Role::query()->where('name', Role::SYSTEM_SUPERADMIN)->first();

        if ($superadminRole !== null) {
            $superadmin->roles()->syncWithoutDetaching([$superadminRole->id]);
        }
    }
}
