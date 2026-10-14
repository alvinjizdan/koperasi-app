<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 89 — Settlement / Pelunasan Pinjaman SHM.
 *
 * ADDITIVE ONLY: tidak ada tabel finansial lama yang diubah, dikurangi, atau
 * ditulis ulang (shm_loans, shm_loan_installments, shm_loan_payments,
 * shm_loan_payment_allocations tetap utuh).
 *
 * Struktur:
 * - `shm_loan_id` → shm_loans, RESTRICT (riwayat settlement tidak hilang saat
 *   pinjaman dihapus) + UNIQUE: SATU record settlement per pinjaman — lapisan
 *   database untuk mencegah settlement ganda di atas guard service (row lock).
 * - `settlement_reference` UNIQUE, dibuat SERVER (bukan payload klien) dengan
 *   pola SHM-SET-YYYYMMDD-XXXXXXXX mengikuti identifiker SHM lain (T67/T69/T86).
 *   Saat ini belum ada jalur penulisan: konfirmasi settlement masih
 *   PENDING BUSINESS CONFIRMATION (lihat config/shm-business-rule-contract.php).
 * - Komponen finansial decimal(18,2): outstanding pokok/bunga/penalty,
 *   unallocated payment, dan settlement_amount — diisi dari persisted state,
 *   bukan hasil formula yang dikarang.
 * - `status` default `pending` (pending → completed mengikuti konsep lifecycle
 *   minimum tiket; cancelled/rejected TIDAK dibuat karena belum ada kontrak).
 * - `created_by` → users (pelaku konfirmasi), RESTRICT.
 *
 * Dampak data eksisting: TABEL BARU — nol baris baru, nol perubahan data lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shm_loan_settlements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shm_loan_id')
                ->constrained('shm_loans')
                ->restrictOnDelete();

            // Referensi settlement server-generated + unik.
            $table->string('settlement_reference', 50)->unique();

            $table->date('settlement_date');

            // Komponen kewajiban (dari persisted state; penalty = 0 selama
            // formula masih PENDING BUSINESS CONFIRMATION).
            $table->decimal('outstanding_principal', 18, 2)->default(0);
            $table->decimal('outstanding_interest', 18, 2)->default(0);
            $table->decimal('outstanding_penalty', 18, 2)->default(0);

            // Overpayment tampil TERPISAH — tidak pernah otomatis mengurangi
            // settlement_amount (perlakuan = PENDING BUSINESS CONFIRMATION).
            $table->decimal('unallocated_payment_amount', 18, 2)->default(0);

            $table->decimal('settlement_amount', 18, 2)->default(0);

            // pending | completed.
            $table->string('status', 20)->default('pending');

            $table->text('notes')->nullable();

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            // SATU settlement per pinjaman — mencegah dua completed settlement
            // meski dua request settlement berjalan paralel (lapisan DB dari
            // guard row lock di ShmLoanSettlementService).
            $table->unique('shm_loan_id');

            $table->index('settlement_date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shm_loan_settlements');
    }
};
