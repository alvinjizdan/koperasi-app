<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T89C — Add member_id to shm_customers.
 *
 * Mengaitkan Nasabah SHM dengan Anggota Koperasi sebagai sumber data.
 * - Kolom `member_id` nullable untuk mempertahankan referential safety terhadap
 *   legacy data (6 baris nasabah yang dibuat sebelum T89C tanpa anggota sumber).
 * - Nasabah SHM baru WAJIB memiliki member_id (divalidasi di level application / request).
 * - Restrict on delete agar member yang menjadi sumber nasabah SHM tidak terhapus diam-diam.
 * - Unique partial/index untuk mencegah duplikasi nasabah aktif per anggota.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shm_customers', function (Blueprint $table) {
            $table->foreignId('member_id')
                ->nullable()
                ->after('id')
                ->constrained('members')
                ->restrictOnDelete();

            $table->index('member_id');
        });
    }

    public function down(): void
    {
        Schema::table('shm_customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('member_id');
        });
    }
};
