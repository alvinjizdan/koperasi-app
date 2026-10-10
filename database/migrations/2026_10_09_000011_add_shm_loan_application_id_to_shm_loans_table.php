<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 69 — tautan pengajuan asal pada pinjaman hasil pencairan.
 *
 * `shm_loans` tidak membawa kolom pengajuan karena sebagian pinjaman dapat
 * dibuat di luar alur pencairan pengajuan, sehingga kolomnya NULLABLE.
 *
 * UNIQUE di database: satu pengajuan hanya pernah menghasilkan satu pinjaman.
 * Keunikan ini ditegakkan di level data (bukan hanya pengecekan di service)
 * sehingga dua permintaan pencairan yang berjalan bersamaan tidak dapat
 * membuat pinjaman ganda dari pengajuan yang sama.
 *
 * RESTRICT: pinjaman adalah catatan keuangan, sehingga pengajuan asalnya tidak
 * boleh terhapus begitu saja.
 *
 * Kolom ini disengaja diisi server saat pencairan, bukan dari payload klien.
 * Karena menambah foreign key, SQLite memerlukan pembangunan ulang tabel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shm_loans', function (Blueprint $table) {
            $table->foreignId('shm_loan_application_id')
                ->nullable()
                ->unique()
                ->constrained('shm_loan_applications')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shm_loans', function (Blueprint $table) {
            $table->dropUnique(['shm_loan_application_id']);
            $table->dropForeign(['shm_loan_application_id']);
        });

        Schema::table('shm_loans', function (Blueprint $table) {
            $table->dropColumn('shm_loan_application_id');
        });
    }
};
