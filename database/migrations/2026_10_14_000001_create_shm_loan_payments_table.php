<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 86 — Transaksi Pembayaran Pinjaman SHM.
 *
 * Domain finansial SHM TERPISAH dari `installment_payments` koperasi:
 * tabel ini hanya berelasi ke `shm_loans`, tidak pernah ke `loans`,
 * `installment_schedules`, maupun `members`.
 *
 * Batasan yang disengaja (T86):
 * - `payment_reference` UNIQUE dan dibuat server-side (bukan payload React);
 * - `idempotency_key` UNIQUE nullable — mengikuti pola existing koperasi
 *   (`installment_payments.idempotency_key`): retry request yang sama
 *   mengembalikan baris yang sama, bukan pembayaran kedua;
 * - `amount` memakai decimal(18,2) — konvensi finansial SHM (bukan FLOAT);
 *   TIDAK pernah dibatasi ke angsuran karena overpayment adalah kontrak T87;
 * - `payment_channel` NULLABLE tanpa enum: daftar kanal resmi masih
 *   PENDING BUSINESS CONFIRMATION (T84) sehingga nilai tidak boleh dikarang;
 * - `status` default `posted` — transaksi finansial tersimpan, bukan status
 *   aplikasi (pending/approved/rejected). Nilai `reversed` disediakan untuk
 *   reversal T88 dan TIDAK pernah ditulis oleh T86;
 * - TIDAK ada kolom alokasi (principal/interest/penalty) — alokasi adalah T87;
 * - `created_by` → users (pencatat pembayaran), RESTRICT agar riwayat tidak
 *   hilang saat akun dihapus.
 *
 * Tabel ini murni ADDITIVE: tidak ada tabel lain yang diubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shm_loan_payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shm_loan_id')
                ->constrained('shm_loans')
                ->restrictOnDelete();

            // Nomor referensi internal, dibuat server; satu-satunya batas
            // keunikan transaksi selain idempotency_key.
            $table->string('payment_reference', 50)->unique();

            $table->date('payment_date');
            $table->decimal('amount', 18, 2);

            // Kanal resmi PENDING (T84) — nullable, tanpa nilai enum buatan.
            $table->string('payment_channel', 50)->nullable();

            // posted | reversed (reversed untuk reversal T88; T86 hanya posted).
            $table->string('status', 20)->default('posted');

            $table->text('notes')->nullable();

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            // Pola idempotensi existing (koperasi): retry request yang sama
            // tidak pernah membuat pembayaran finansial kedua.
            $table->string('idempotency_key', 100)->nullable()->unique();

            $table->timestamps();

            $table->index('shm_loan_id');
            $table->index('payment_date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shm_loan_payments');
    }
};
