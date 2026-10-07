<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 44 — keputusan approval pengajuan SHM (single stage).
 *
 * MVP ini sengaja memakai SATU tahap approval. Multi-level approval adalah
 * PENDING BUSINESS DECISION dan tidak diimplementasikan di sini.
 *
 * decided_by diisi dari pengguna yang benar-benar melakukan keputusan, bukan
 * dari input form, sehingga approve/reject atas nama orang lain tidak mungkin
 * terjadi melalui request langsung.
 *
 * Batas kewenangan approval, siapa yang berhak menyetujui, dan dokumen
 * apa saja yang harus lengkap sebelum approve, semuanya PENDING BUSINESS
 * DECISION. Tabel ini hanya mencatat fakta keputusan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shm_approvals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shm_application_id')
                ->constrained('shm_applications')
                ->cascadeOnDelete();

            // Nullable selama pengajuan belum diputuskan.
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();

            // pending | approved | rejected
            $table->string('status', 20)->default('pending');

            // Diisi server saat keputusan diambil.
            $table->timestamp('decided_at')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('shm_application_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shm_approvals');
    }
};
