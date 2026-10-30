<?php

namespace Database\Seeders\Demo;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * T011 — Demo User Seeder.
 *
 * Menyiapkan akun representative untuk setiap role operasional yang aktif di sistem:
 * - superadmin: superadmin@ksp.local (Superadmin Koperasi)
 * - admin: admin.demo@ksp.local (Budi Admin Pratama)
 * - direktur_utama: direktur.demo@ksp.local (Hendrawan President Director)
 * - branch_manager: bm.demo@ksp.local (Faisal Branch Manager)
 * - marketing: marketing.demo@ksp.local (Rian Marketing Utama)
 *
 * Password seragam: 'password' (demo-only, deterministik, firstOrCreate / updateOrCreate).
 */
class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'email' => 'admin.demo@ksp.local',
                'name' => 'Budi Admin Pratama',
                'role' => 'admin',
            ],
            [
                'email' => 'direktur.demo@ksp.local',
                'name' => 'Hendrawan President Director',
                'role' => 'direktur_utama',
            ],
            [
                'email' => 'bm.demo@ksp.local',
                'name' => 'Faisal Branch Manager',
                'role' => 'branch_manager',
            ],
            [
                'email' => 'marketing.demo@ksp.local',
                'name' => 'Rian Marketing Utama',
                'role' => 'marketing',
            ],
        ];

        foreach ($users as $u) {
            $user = User::query()->firstOrCreate(
                ['email' => $u['email']],
                [
                    'name' => $u['name'],
                    'password' => Hash::make('password'),
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]
            );

            $role = Role::query()->where('name', $u['role'])->first();
            if ($role !== null) {
                $user->roles()->syncWithoutDetaching([$role->id]);
            }
        }
    }
}
