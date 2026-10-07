<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 44 — objek SHM/SHGB yang berkaitan dengan pengajuan.
 *
 * certificate_type hanya membedakan dua jenis dokumen: SHM dan SHGB.
 * Itu adalah pembeda teknis jenis sertifikat, BUKAN pernyataan bahwa salah
 * satu jenis lebih layak atau wajib. Seluruh aturan legal SHM vs SHGB
 * (masa berlaku, luas minimal, batas umur sertifikat, dll.) adalah PENDING
 * BUSINESS DECISION dan tidak diimplementasikan di sini.
 *
 * Koordinat bersifat nullable dan hanya informatif untuk membantu Marketing
 * menemukan objek. Koordinat BUKAN bukti kepemilikan dan tidak dipakai untuk
 * validasi radius apa pun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shm_properties', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shm_application_id')
                ->constrained('shm_applications')
                ->cascadeOnDelete();

            // Hanya pembeda jenis sertifikat. Tidak ada aturan legal turunan.
            $table->string('certificate_type', 10);

            // Nullable: belum ada keputusan KSP kapan sertifikat harus tersedia.
            $table->string('certificate_number', 100)->nullable();
            $table->string('owner_name', 150)->nullable();

            // Alamat objek. Berbeda dari alamat nasabah pada tabel members.
            $table->text('address');

            // Lokasi objek, opsional. Sama sekali tidak dipakai sebagai syarat.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 11, 7)->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('certificate_type');
            $table->index('shm_application_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shm_properties');
    }
};
