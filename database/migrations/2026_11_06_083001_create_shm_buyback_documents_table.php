<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 7 — Pengelolaan Dokumen Legalitas Memorandum Buyback.
 *
 * Menyiapkan tabel dedicated shm_buyback_documents untuk 5 jenis dokumen:
 * - shm
 * - akta_buyback
 * - kuasa_awal
 * - ppjb
 * - pengosongan
 *
 * Menggunakan UNIQUE constraint (shm_memorandum_id, document_type) untuk menjamin 1 slot = 1 file aktif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shm_buyback_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shm_memorandum_id')
                ->constrained('shm_memorandums')
                ->cascadeOnDelete();

            $table->string('document_type', 50);
            $table->string('file_name', 255);
            $table->string('file_path', 500);
            $table->string('mime_type', 100)->default('application/pdf');
            $table->unsignedBigInteger('file_size')->default(0);

            $table->foreignId('uploaded_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            $table->unique(['shm_memorandum_id', 'document_type']);
            $table->index('document_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shm_buyback_documents');
    }
};
