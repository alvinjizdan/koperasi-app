<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 25 — snapshot posisi saldo pinjaman per tanggal posisi.
 *
 * Snapshot adalah SALINAN TERTULIS dari sumber saldo otoritatif (jadwal
 * angsuran) pada saat pengambilan — bukan transaksi finansial dan tidak pernah
 * mengubah saldo/keputusan finansial apa pun. Tanggal posisi (as_of_date)
 * disimpan terpisah dari waktu pembuatan aktual (created_at) agar laporan
 * dapat membedakan "posisi pada tanggal X" dari "kapan data diambil".
 *
 * Immutable via jalur aplikasi: model menonaktifkan update; koreksi hanya
 * melalui mekanisme yang dapat ditelusuri (belum ada kebutuhan bisnis).
 * Unik per (loan_id, as_of_date, context) untuk mencegah snapshot ganda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_balance_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->restrictOnDelete();
            $table->date('as_of_date');
            $table->string('context', 50)->default('manual'); // manual | batch | (luas: sumber pemanggil)
            $table->string('status', 20); // status pinjaman saat snapshot
            $table->decimal('outstanding_principal', 15, 2);
            $table->decimal('outstanding_interest', 15, 2);
            $table->decimal('outstanding_penalty', 15, 2);
            $table->decimal('total_owed', 15, 2);
            $table->decimal('total_paid', 15, 2)->nullable(); // pembayaran COMPLETED
            $table->unsignedInteger('schedule_count')->default(0);
            $table->unsignedInteger('unpaid_schedule_count')->default(0);
            $table->foreignId('captured_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['loan_id', 'as_of_date', 'context'], 'loan_snapshots_loan_date_context_unique');
            $table->index('as_of_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_balance_snapshots');
    }
};
