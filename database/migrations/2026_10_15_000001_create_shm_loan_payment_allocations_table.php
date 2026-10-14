<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 87 — Alokasi Pembayaran Pinjaman SHM ke Angsuran.
 *
 * ADDITIVE ONLY: tidak ada kolom/tabel finansial yang diubah atau dihapus.
 *
 * Dua penambahan:
 *
 * 1. Split paid pada `shm_loan_installments` (paid_interest_amount,
 *    paid_principal_amount) — diperlukan untuk urutan alokasi bunga → pokok
 *    dan untuk invariant: original = paid + outstanding per komponen.
 *    Default 0 konsisten dengan data eksisting (jadwal baru paid = 0).
 *    `paid_amount` tetap total paid (= paid_interest + paid_principal);
 *    `remaining_amount` tetap total_amount − paid_amount (semantik T74).
 *
 * 2. Tabel `shm_loan_payment_allocations` — jejak auditabel alokasi per
 *    (payment, installment). Unique constraint mencegah alokasi ganda untuk
 *    pasangan yang sama (idempotensi + proteksi race). `penalty_amount`
 *    default 0: formula penalty masih PENDING BUSINESS CONFIRMATION, T87
 *    tidak pernah menulis nilai ≠ 0.
 *
 * Dampak data eksisting: kolom baru default 0; tidak ada backfill; tidak
 * ada pembayaran/anngsuran historis yang diubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shm_loan_installments', function (Blueprint $table) {
            // Split paid per komponen (T87 alokasi bunga → pokok).
            $table->decimal('paid_interest_amount', 18, 2)->default(0)->after('paid_amount');
            $table->decimal('paid_principal_amount', 18, 2)->default(0)->after('paid_interest_amount');
        });

        Schema::create('shm_loan_payment_allocations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shm_loan_payment_id')
                ->constrained('shm_loan_payments')
                ->restrictOnDelete();

            $table->foreignId('shm_loan_installment_id')
                ->constrained('shm_loan_installments')
                ->restrictOnDelete();

            // Komponen alokasi. penalty default 0 — formula penalty PENDING;
            // T87 tidak pernah menghitung atau menulis penalty ≠ 0.
            $table->decimal('penalty_amount', 18, 2)->default(0);
            $table->decimal('interest_amount', 18, 2)->default(0);
            $table->decimal('principal_amount', 18, 2)->default(0);
            $table->decimal('total_amount', 18, 2)->default(0);

            $table->timestamps();

            // Idempotensi: satu payment tidak pernah mengalokasikan dua kali
            // ke angsuran yang sama.
            $table->unique(['shm_loan_payment_id', 'shm_loan_installment_id']);
            $table->index('shm_loan_installment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shm_loan_payment_allocations');

        Schema::table('shm_loan_installments', function (Blueprint $table) {
            $table->dropColumn(['paid_interest_amount', 'paid_principal_amount']);
        });
    }
};
