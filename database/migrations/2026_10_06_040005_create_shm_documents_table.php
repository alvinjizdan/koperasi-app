<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 44 — checklist kelengkapan dokumen pengajuan SHM.
 *
 * Daftar dokumen yang WAJIB disediakan KSP belum ada. Karena itu tabel ini
 * tidak menyimpan daftar dokumen keras; item checklist dibuat oleh pengguna
 * saat dibutuhkan dan dapat ditambah atau dihapus selagi pengajuan belum
 * keluar dari tahap draft.
 *
 * TIDAK ada upload berkas pada ticket ini: tidak ada inferensi_storage,
 * tidak ada scanning, tidak ada OCR, dan tidak ada tanda tangan digital.
 * Kolom isian hanya mencatat kelengkapan secara manual beserta catatan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shm_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shm_application_id')
                ->constrained('shm_applications')
                ->cascadeOnDelete();

            // Nama dokumen bebas. Tidak ada daftar legal yang dikunci di sini.
            $table->string('document_name', 150);

            // pending | complete | not_applicable
            $table->string('status', 20)->default('pending');

            $table->foreignId('checked_by')->nullable()->constrained('users')->restrictOnDelete();

            // Diisi server saat kelengkapan dicatat.
            $table->timestamp('checked_at')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('shm_application_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shm_documents');
    }
};
