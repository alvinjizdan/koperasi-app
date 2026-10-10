<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 64 — record Pinjaman milik nasabah SHM.
 *
 * Domain finansial SHM TERPISAH dari pinjaman koperasi (`loans`): tidak ada
 * relasi ke `members`, tidak memakai tabel angsuran/pembayaran koperasi, dan
 * tidak membaca business rule koperasi. Nasabahnya adalah `shm_customers`.
 *
 * Batasan yang disengaja:
 * - `rate_percent` default 2 adalah rate resmi SHM;
 * - `rate_period` dan `calculation_method` NULLABLE dan TIDAK PERNAH diisi
 *   otomatis oleh sistem: keduanya hanya terisi bila pengguna memilih secara
 *   eksplisit, karena periodisasi dan formula angsuran masih PENDING
 *   BUSINESS CONFIRMATION. Tanpa keduanya tabel angsuran tidak dapat dibangun;
 * - `status` dibatasi pada active|closed di level aplikasi;
 * - seluruh foreign key memakai RESTRICT agar riwayat pinjaman tidak hilang
 *   saat master/nasabah dihapus.
 *
 * Kolom `shm_loan_application_id` (pengajuan asal pencairan) DITAMBAHKAN oleh
 * migrasi 2026_10_09_000011, bukan oleh migrasi ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shm_loans', function (Blueprint $table) {
            $table->id();

            // Nomor pinjaman dibuat server-side, tidak pernah dari input klien.
            $table->string('loan_number', 50)->unique();

            $table->foreignId('shm_customer_id')
                ->constrained('shm_customers')
                ->restrictOnDelete();

            $table->foreignId('shm_loan_product_id')
                ->constrained('shm_loan_products')
                ->restrictOnDelete();

            $table->decimal('principal', 18, 2);
            $table->unsignedInteger('tenor_months');

            // Rate resmi SHM = 2%. Periodisasi & formula masih PENDING.
            $table->decimal('rate_percent', 8, 4)->default(2);
            $table->string('rate_period', 10)->nullable();
            $table->string('calculation_method', 20)->nullable();

            $table->string('location', 150)->nullable();
            $table->string('purpose', 255)->nullable();

            // active | closed (ditegakkan di level aplikasi).
            $table->string('status', 20)->default('active');

            // Diisi server saat pencairan; NULL selama pinjaman belum cair.
            $table->date('disbursed_at')->nullable();

            $table->text('note')->nullable();

            $table->timestamps();

            $table->index('shm_customer_id');
            $table->index('shm_loan_product_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shm_loans');
    }
};
