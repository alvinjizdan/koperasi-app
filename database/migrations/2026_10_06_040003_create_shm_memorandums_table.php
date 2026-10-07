<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 44 — memorandum pengajuan SHM.
 *
 * Memorandum pada tabel ini hanya tempat menyimpan naskah dan status pengajuannya.
 * TIDAK ada kolom nominal, margin, bunga, bagi hasil, atau formula keuangan
 * apa pun: semua angka financials SHM masih PENDING BUSINESS DECISION.
 *
 * Rumus memorandum sengaja tidak dikunci. Naskah disimpan sebagai teks bebas
 * dan akan dipindahkan ke struktur perhitungan tersendiri ketika KSP memberi
 * aturanfinansial yang sah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shm_memorandums', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shm_application_id')
                ->constrained('shm_applications')
                ->cascadeOnDelete();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();

            // Diisi server, bukan dari input pengguna.
            $table->timestamp('submitted_at')->nullable();

            // draft | submitted. Status pengajuan memorandum, bukan status kredit.
            $table->string('status', 20)->default('draft');

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('shm_application_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shm_memorandums');
    }
};
