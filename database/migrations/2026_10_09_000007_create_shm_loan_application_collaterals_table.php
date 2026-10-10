<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 67 Section 5 — jaminan pada sebuah Pengajuan Pinjaman SHM.
 *
 * `collateral_type` dan `binding_status` berupa teks bebas karena master
 * resminya belum tersedia (PENDING BUSINESS CONFIRMATION). Tidak ada kolom
 * foto: infrastructure upload storage belum ada (PENDING INFRASTRUCTURE).
 *
 * Foreign key memakai CASCADE: baris jaminan adalah bagian dari pengajuan,
 * sehingga ikut hilang bila pengajuan dihapus.
 *
 * Nilai bersifat nullable karena sebagian jaminan belum dinilai saat
 * pencatatan awal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shm_loan_application_collaterals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shm_loan_application_id')
                ->constrained('shm_loan_applications')
                ->cascadeOnDelete();

            $table->date('collateral_date')->nullable();

            // Teks bebas — master resmi belum ada.
            $table->string('collateral_type', 150);
            $table->string('collateral_name', 150);

            $table->text('notes')->nullable();

            $table->decimal('estimated_value', 18, 2)->nullable();

            // Teks bebas — master resmi belum ada.
            $table->string('binding_status', 100)->nullable();
            $table->date('binding_date')->nullable();
            $table->decimal('rights_value', 18, 2)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shm_loan_application_collaterals');
    }
};
