<?php

namespace Database\Seeders\Demo;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ShmLoanProductSeeder;
use Illuminate\Database\Seeder;

/**
 * T011 — Demo Database Seeder (Dedicated Entry Point).
 *
 * Mengoordinasikan seluruh tahapan seeding demo secara deterministik:
 * 1. Fondasi permission, role, setting & produk resmi SHM
 * 2. Akun pengguna representative (Superadmin, Admin, Marketing, Kasir, Pengawas)
 * 3. Master simpanan koperasi & 12 anggota koperasi realistis
 * 4. 10 Nasabah SHM terverifikasi
 * 5. Master akuntansi SHM (COA, Product Accounting, Accounting Mappings)
 * 6. Eksekusi alur bisnis end-to-end via Production Services (Disbursement, Payment, Settlement, Correction, Collection)
 *
 * Cara eksekusi:
 * php artisan db:seed --class=Database\\Seeders\\Demo\\DemoDatabaseSeeder
 */
class DemoDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Fondasi Sistem
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            SettingsSeeder::class,
            ShmLoanProductSeeder::class,
            DatabaseSeeder::class,
        ]);

        // 2. Akun Pengguna Demo
        $this->call(DemoUserSeeder::class);

        // 3. Anggota Koperasi & Simpanan Demo
        $this->call(DemoMemberSeeder::class);

        // 4. Nasabah SHM Demo
        $this->call(DemoShmCustomerSeeder::class);

        // 5. Akuntansi SHM (COA, Product Accounting, Mappings)
        $this->call(DemoAccountingSeeder::class);

        // 6. Alur Transaksi & Finansial End-to-End
        $this->call(DemoShmWorkflowSeeder::class);
    }
}
