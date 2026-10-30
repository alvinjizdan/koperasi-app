<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TICKET — ROLE MANAGEMENT & USER CRUD
 *
 * Penghapusan Role `kasir` dan `pengawas`:
 * 1. Menghapus role assignment dari role_user secara aman (tidak menghapus user, tidak menghapus historical data).
 * 2. Menghapus relasi permission dari permission_role untuk kedua role tersebut (tidak menghapus modul/permission).
 * 3. Menghapus baris role `kasir` dan `pengawas` dari tabel roles.
 * 4. Mengaktifkan role `direktur_utama` (President Director).
 * 5. Membuat role `branch_manager` (Branch Manager) jika belum ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rolesToDelete = ['kasir', 'pengawas'];

        $roleIds = DB::table('roles')
            ->whereIn('name', $rolesToDelete)
            ->pluck('id')
            ->all();

        if (! empty($roleIds)) {
            // 1. Detach dari user
            DB::table('role_user')->whereIn('role_id', $roleIds)->delete();

            // 2. Detach permission
            DB::table('permission_role')->whereIn('role_id', $roleIds)->delete();

            // 3. Hapus baris role
            DB::table('roles')->whereIn('id', $roleIds)->delete();
        }

        // 4. Aktifkan President Director (direktur_utama)
        DB::table('roles')
            ->where('name', 'direktur_utama')
            ->update([
                'display_name' => 'President Director (Direktur Utama)',
                'is_active' => true,
                'is_system' => true,
                'updated_at' => now(),
            ]);

        // 5. Tambahkan Branch Manager jika belum ada
        $bmExists = DB::table('roles')->where('name', 'branch_manager')->exists();
        if (! $bmExists) {
            DB::table('roles')->insert([
                'name' => 'branch_manager',
                'display_name' => 'Branch Manager',
                'description' => 'Pimpinan cabang: pengawasan operasional, eskalasi, dan pemantauan kinerja cabang.',
                'is_system' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Re-create kasir & pengawas if rolling back
        $kasirExists = DB::table('roles')->where('name', 'kasir')->exists();
        if (! $kasirExists) {
            DB::table('roles')->insert([
                'name' => 'kasir',
                'display_name' => 'Kasir',
                'description' => 'Operasional kas harian: sesi kasir, penerimaan, pengeluaran, setoran.',
                'is_system' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $pengawasExists = DB::table('roles')->where('name', 'pengawas')->exists();
        if (! $pengawasExists) {
            DB::table('roles')->insert([
                'name' => 'pengawas',
                'display_name' => 'Pengawas / Auditor',
                'description' => 'Akses baca untuk pengawasan dan audit.',
                'is_system' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('roles')
            ->where('name', 'direktur_utama')
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);

        DB::table('roles')->where('name', 'branch_manager')->delete();
    }
};
