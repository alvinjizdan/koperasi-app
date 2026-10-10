<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 68 — tautan pemilik & approval pada Pengajuan Pinjaman SHM.
 *
 * Menambah dua kolom yang sengaja TIDAK ada di migrasi pembuatan tabel:
 *
 * - `marketing_id`      : pemilik pengajuan, diisi server dari authenticated
 *                         user saat create. RESTRICT agar riwayat pengajuan
 *                         tidak hilang bila user dihapus.
 * - `shm_application_id`: mirror pengajuan legacy (`shm_applications`) yang
 *                         membawa baris ShmApproval modul Approval SHM
 *                         existing. NULL selama pengajuan belum diajukan
 *                         approval. SET NULL agar nonaktifnya mirror tidak
 *                         menghapus pengajuan.
 *
 * Keduanya NULLABLE dan diisi server, bukan dari payload klien, sehingga
 * pengajuan orang lain tidak dapat diklaim lewat request langsung.
 *
 * Karena keduanya menambah foreign key, SQLite memerlukan pembangunan ulang
 * tabel; indeks dibuang lebih dulu pada proses down() supaya DROP COLUMN
 * tidak menolak indeks yang masih menunjuk kolom tersebut.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shm_loan_applications', function (Blueprint $table) {
            $table->foreignId('marketing_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('shm_application_id')
                ->nullable()
                ->constrained('shm_applications')
                ->nullOnDelete();

            $table->index('marketing_id');
            $table->index('shm_application_id');
        });
    }

    public function down(): void
    {
        Schema::table('shm_loan_applications', function (Blueprint $table) {
            $table->dropForeign(['marketing_id']);
            $table->dropForeign(['shm_application_id']);
            $table->dropIndex(['marketing_id']);
            $table->dropIndex(['shm_application_id']);
        });

        Schema::table('shm_loan_applications', function (Blueprint $table) {
            $table->dropColumn(['marketing_id', 'shm_application_id']);
        });
    }
};
