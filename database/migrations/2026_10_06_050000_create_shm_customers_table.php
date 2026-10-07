<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 46 — Master data Nasabah SHM.
 *
 * Nasabah SHM adalah business domain tersendiri dan BERBEDA dari anggota
 * koperasi (`members`). Tabel ini sengaja TIDAK memiliki foreign key ke
 * `members`: keputusan KSP adalah nasabah SHM tidak dianggap anggota koperasi,
 * sehingga seorang nasabah SHM boleh belum/tidak menjadi anggota.
 *
 * Batasan yang disengaja:
 * - customer_number UNIQUE dan dibuat server-side (lihat ShmCustomerService);
 * - nik nullable karena workflow saat ini belum mewajibkannya;
 * - latitude/longitude nullable; koordinat tidak dipakai sebagai validasi apa pun;
 * - is_active dipakai untuk menonaktifkan nasabah tanpa menghapus datanya,
 *   mengikuti pola is_active pada role;
 * - TIDAK ada kolom penghasilan, pekerjaan, pasangan, NPWP, status perkawinan,
 *   maupun kolom finansial apa pun. Field bisnis lain belum diputuskan KSP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shm_customers', function (Blueprint $table) {
            $table->id();

            // Nomor nasabah dibuat server-side, tidak pernah dari input klien.
            $table->string('customer_number', 50)->unique();

            $table->string('name', 150);

            // NIK boleh kosong selama workflow belum mewajibkannya.
            $table->string('nik', 50)->nullable();

            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->text('address')->nullable();

            // Titik lokasi nasabah. Rentang latitude -90..90 (3 digit sebelum
            // koma) dan longitude -180..180 (4 digit sebelum koma). Desimal,
            // bukan float, agar koordinat tidak bergeser saat disimpan.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 11, 7)->nullable();

            // Nonaktif bukan berarti dihapus: pengajuan lama tetap merujuk ke baris ini.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index('name');
            $table->index('nik');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shm_customers');
    }
};
