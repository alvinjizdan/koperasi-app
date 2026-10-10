<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 67 Section 11 — persyaratan tambahan bila pinjaman disetujui.
 *
 * Daftar bebas dibuat pengguna — mengikuti pola checklist dokumen SHM existing
 * (ShmDocument) tanpa daftar wajib yang dikunci.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shm_loan_application_requirements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shm_loan_application_id')
                ->constrained('shm_loan_applications')
                ->cascadeOnDelete();

            $table->string('requirement', 255);

            $table->boolean('is_done')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shm_loan_application_requirements');
    }
};
