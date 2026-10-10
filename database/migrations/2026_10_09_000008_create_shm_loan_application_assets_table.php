<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 67 Section 4 — barang agunan kredit pada sebuah Pengajuan Pinjaman SHM.
 *
 * Hanya jenis barang, nomor, tahun, dan nilai perkiraan — sesuai contract UI
 * T66/T67 tanpa business rule tambahan. Tidak ada kolom foto: infrastructure
 * upload storage belum ada (PENDING INFRASTRUCTURE).
 *
 * Foreign key memakai CASCADE: baris barang agunan adalah bagian dari
 * pengajuan, sehingga ikut hilang bila pengajuan dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shm_loan_application_assets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shm_loan_application_id')
                ->constrained('shm_loan_applications')
                ->cascadeOnDelete();

            // Teks bebas — master jenis barang resmi belum dikunci.
            $table->string('asset_type', 150);
            $table->string('asset_number', 100)->nullable();
            $table->string('asset_year', 4)->nullable();

            $table->decimal('estimated_value', 18, 2)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shm_loan_application_assets');
    }
};
