<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 44 — SHM MVP Foundation.
 *
 * Tabel induk: shm_applications (satu pengajuan SHM/SHGB).
 *
 * Batasan yang disengaja:
 * - application_number UNIQUE dan dibuat server-side (lihat ShmApplicationService);
 * - member_id NULLABLE karena KSP belum memutuskan apakah nasabah SHM wajib
 *   menjadi anggota. Data calon nasabah disimpan pada kolom prospect_* agar sistem
 *   tidak memaksa membership dan aturan keanggotaan institusi tidak berubah;
 * - marketing_id memakai restrictOnDelete agar riwayat pengajuan tidak hilang
 *   bila user dihapus. Actor diambil dari pengguna yang sedang login, bukan
 *   dari input form;
 * - TIDAK ada kolom nominal, bunga, margin, diskon, atau formula keuangan
 *   apa pun. Ticket ini belum memiliki aturan finansial SHM.
 *
 * Tabel ini TIDAK terhubung ke tabel loans: SHM adalah proses pra-pembiayaan,
 * bukan record pinjaman. Pembiayaan SHM ke modul pinjaman adalah ticket
 * berikutnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shm_applications', function (Blueprint $table) {
            $table->id();

            // Nomor pengajuan dibuat server-side, tidak pernah dari input klien.
            $table->string('application_number', 32)->unique();

            // Nasabah. NULLABLE: belum ada keputusan KSP bahwa nasabah SHM wajib member.
            $table->foreignId('member_id')->nullable()->constrained('members')->restrictOnDelete();

            // Data calon nasabah untuk pengajuan yang belum terhubung ke anggota.
            $table->string('prospect_name', 150)->nullable();
            $table->string('prospect_phone', 30)->nullable();

            // Marketing penanggung jawab. Selalu dari authenticated user.
            $table->foreignId('marketing_id')->constrained('users')->restrictOnDelete();

            // Aktivitas Marketing yang menjadi asal pengajuan ini. NULLABLE karena
            // Marketing boleh membuat pengajuan tanpa check-in terlebih dahulu.
            // Relasi ini memakai sistem Marketing Activity yang sudah ada; tidak
            // ada pencatatan aktivitas atau pelacakan lokasi yang baru.
            $table->foreignId('marketing_activity_id')
                ->nullable()
                ->constrained('marketing_activities')
                ->nullOnDelete();

            // Status lifecycle. Nilai yang sah dikunci di App\Enums\ShmApplicationStatus.
            $table->string('status', 40)->default('draft');

            // Seluruh timestamp workflow diisi server saat transisi terjadi.
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('disbursed_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Motivation/notes bebas. Batas panjang ditegakkan di Form Request.
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('marketing_id');
            $table->index('marketing_activity_id');
            $table->index('member_id');
            $table->index('status');
            $table->index('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shm_applications');
    }
};
