<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 47 — Credit Checking sebagai workflow operasional.
 *
 * Tabel `shm_credit_checks` sudah menyediakan sebagian besar yang dibutuhkan:
 * `status`, `filled_by`, `checked_at`, `result`, dan `notes`. Ticket ini hanya
 * menambah yang benar-benar belum ada, tanpa mengganti nama kolom lama:
 *
 * - `started_at` : kapan pengerjaan dimulai. Sebelumnya tidak ada, sehingga
 *                  durasi pengerjaan tidak dapat diketahui.
 * - `outcome`    : hasil pencatatan (pass/review/fail). BUKAN skor atau
 *                  keputusan kelayakan, dan tidak memicu approval otomatis.
 *
 * Pemetaan istilah ticket ke kolom yang sudah ada (tidak ada kolom duplikat):
 * - checked_by     -> filled_by
 * - completed_at   -> checked_at
 * - result_summary -> result
 * - checking_notes -> notes
 *
 * `status` tetap string dan nilainya diperluas di level aplikasi
 * (App\Enums\ShmCreditCheckStatus) dengan tambahan `in_progress`. Tidak ada
 * perubahan tipe kolom dan tidak ada data lama yang disentuh.
 *
 * Indeks `shm_application_id` yang lama hanya mempercepat pencarian; indeks itu
 * diganti dengan indeks UNIK karena workflow Ticket 47 menetapkan satu
 * pengajuan hanya memiliki satu baris credit checking. Tanpa keunikan di level
 * data, dua permintaan "mulai checking" yang berjalan bersamaan dapat membuat
 * baris ganda. Tabel ini masih kosong saat migrasi berjalan (tidak ada
 * pengajuan SHM), sehingga penggantian indeks tidak menghapus data.
 *
 * Index pada `outcome` dan `checked_at` dipakai daftar Hasil Marketing yang
 * memfilter/mengurutkan berdasarkan hasil dan waktu penyelesaian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shm_credit_checks', function (Blueprint $table) {
            // pass | review | fail. NULL selama proses belum selesai.
            $table->string('outcome', 20)->nullable()->after('status');

            // Waktu sistem saat pengerjaan dimulai.
            $table->timestamp('started_at')->nullable()->after('filled_by');

            $table->index('outcome');
            $table->index('checked_at');
        });

        Schema::table('shm_credit_checks', function (Blueprint $table) {
            $table->dropIndex(['shm_application_id']);
        });

        Schema::table('shm_credit_checks', function (Blueprint $table) {
            $table->unique('shm_application_id');
        });
    }

    public function down(): void
    {
        Schema::table('shm_credit_checks', function (Blueprint $table) {
            $table->dropUnique(['shm_application_id']);
        });

        Schema::table('shm_credit_checks', function (Blueprint $table) {
            $table->index('shm_application_id');
        });

        // Index dibuang lebih dulu: SQLite menolak DROP COLUMN selama masih ada
        // indeks yang menunjuk kolom tersebut, sedangkan PostgreSQL tidak.
        Schema::table('shm_credit_checks', function (Blueprint $table) {
            $table->dropIndex(['outcome']);
            $table->dropIndex(['checked_at']);
        });

        Schema::table('shm_credit_checks', function (Blueprint $table) {
            $table->dropColumn(['outcome', 'started_at']);
        });
    }
};
