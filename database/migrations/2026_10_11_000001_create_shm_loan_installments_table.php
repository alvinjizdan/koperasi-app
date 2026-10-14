<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 74 — SHM Installment Contract & Data Model.
 *
 * Struktur data angsuran pinjaman SHM (`shm_loan_installments`).
 * Domain finansial SHM TERPISAH dari tabel angsuran koperasi (`installment_schedules`):
 * tidak ada relasi ke `loans`, `members`, atau aturan koperasi.
 *
 * Batasan penting:
 * - Foreign key ke `shm_loans` dengan `cascadeOnDelete` atau `restrictOnDelete` (RESTRICT
 *   agar data angsuran historis tidak hilang sengaja/tidak sengaja).
 * - Nomor urut angsuran (`installment_number`) unique per pinjaman (`shm_loan_id`, `installment_number`).
 * - Nilai nominal menggunakan presisi standar finansial PostgreSQL: `decimal(18, 2)`.
 * - Status default awal adalah 'scheduled'.
 * - Tanggal jatuh tempo `due_date`.
 * - Komponen jadwal angsuran: `principal_amount`, `interest_amount`, `total_amount`.
 * - Komponen pelacakan pembayaran: `paid_amount`, `remaining_amount`, `paid_at`.
 * - Rumus perhitungan dan alokasi pembayaran TIDAK diimplementasikan pada migrasi ini
 *   (PENDING BUSINESS CONFIRMATION).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shm_loan_installments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shm_loan_id')
                ->constrained('shm_loans')
                ->restrictOnDelete();

            $table->unsignedInteger('installment_number');
            $table->date('due_date');

            // Komponen nominal jadwal (ditetapkan saat jadwal dibuat)
            $table->decimal('principal_amount', 18, 2)->default(0);
            $table->decimal('interest_amount', 18, 2)->default(0);
            $table->decimal('total_amount', 18, 2)->default(0);

            // Komponen pelacakan pembayaran
            $table->decimal('paid_amount', 18, 2)->default(0);
            $table->decimal('remaining_amount', 18, 2)->default(0);

            // scheduled | paid (status lanjutan pending business confirmation)
            $table->string('status', 30)->default('scheduled');

            $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            $table->unique(['shm_loan_id', 'installment_number']);
            $table->index('shm_loan_id');
            $table->index('due_date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shm_loan_installments');
    }
};
