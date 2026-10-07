<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penyesuaian struktur role pengguna — menonaktifkan role lama tanpa menghapusnya.
 *
 * Role `petugas_simpanan` dan `petugas_pinjaman` tidak lagi dipakai struktur
 * operasional KSP. Baris role TIDAK dihapus karena:
 * - `role_user` memakai restrictOnDelete, sehingga role yang masih dipakai
 *   pengguna tidak dapat dihapus tanpa merusak relasi;
 * - nama role pada data historis harus tetap dapat ditelusuri.
 *
 *Setelah role dinonaktifkan, role lama tidak lagi ditawarkan pada halaman
 * pengguna/role dan tidak dapat lagi ditugaskan melalui validasi. Row-nya,
 * permission-nya, dan audit trail-nya tetap utuh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            // is_active = false berarti role dinonaktifkan: tidak lagi ditawarkan
            // dan tidak dapat ditugaskan, tetapi datanya tetap utuh.
            $table->boolean('is_active')->default(true)->after('is_system');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
