<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T005 — SHM Settlement Correction.
 *
 * Mengimplementasikan Policy B — Current Period Correction:
 * Tabel pencatat relasi koreksi antara settlement original (yang di-reversi)
 * dan replacement settlement yang baru diterbitkan.
 *
 * ADDITIVE ONLY: tidak ada tabel lama yang dihapus atau diubah skemanya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shm_settlement_corrections', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shm_loan_id')
                ->constrained('shm_loans')
                ->restrictOnDelete();

            // SATU koreksi per original settlement — proteksi database level
            // terhadap double correction / race condition.
            $table->foreignId('original_settlement_id')
                ->unique('shm_settlement_corrections_original_unique')
                ->constrained('shm_loan_settlements')
                ->restrictOnDelete();

            $table->foreignId('replacement_settlement_id')
                ->nullable()
                ->constrained('shm_loan_settlements')
                ->restrictOnDelete();

            $table->text('reason');

            $table->string('status', 30)->default('completed');

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shm_settlement_corrections');
    }
};
