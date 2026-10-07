<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 44 — proses credit checking pada pengajuan SHM.
 *
 * PENTING: ticket ini TIDAK mengimplementasikan BI Checking, SLIK, atau API
 * eksternal apa pun. Metode dan hasil BI Checking masih PENDING BUSINESS
 * DECISION dari KSP.
 *
 * Status di sini hanya status workflow sederhana (pending/completed) yang
 * dapat dikembangkan, BUKAN penilaian kredit, skor, atau keputusan
 * kelayakan. Tidak ada angka batas, tidak ada aturan menolak otomatis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shm_credit_checks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shm_application_id')
                ->constrained('shm_applications')
                ->cascadeOnDelete();

            // filled_by berasal dari pengguna yang melakukan proses, bukan dari form.
            $table->foreignId('filled_by')->nullable()->constrained('users')->restrictOnDelete();

            // Diisi server saat proses dicatat.
            $table->timestamp('checked_at')->nullable();

            // pending | completed. Status workflow, bukan status kelayakan kredit.
            $table->string('status', 20)->default('pending');

            $table->text('result')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('shm_application_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shm_credit_checks');
    }
};
