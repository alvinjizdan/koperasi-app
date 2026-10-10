<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 71 — Aktivitas Marketing SHM memakai Nasabah SHM, bukan anggota koperasi.
 *
 * Setelah Ticket 63 seluruh rute aktivitas/kunjungan Marketing berada di dalam
 * boundary SHM (/shm/marketing) dan hubungannya dengan pengajuan SHM
 * (shm_applications.marketing_activity_id) membuat tabel ini menjadi data
 * domain SHM. Namun kolom tujuan kunjungan masih menunjuk ke `members`
 * (anggota koperasi), sehingga SHM Marketing Activity bergantung pada master
 * koperasi — persis batas domain yang dilarang ticket ini.
 *
 * Perubahan:
 * - `shm_customer_id` menjadi satu-satunya rujukan nasabah pada aktivitas dan
 *   WAJIB terisi (NOT NULL) supaya tidak mungkin ada aktivitas tanpa nasabah
 *   maupun aktivitas yang menunjuk anggota koperasi.
 * - `member_id` DIHAPUS (foreign key, indeks, dan kolomnya).
 *
 * KEAMANAN DATA: migrasi ini sengaja MENOLAK berjalan bila tabel masih berisi
 * baris. Konversi anggota koperasi -> nasabah SHM adalah perubahan data yang
 * dilarang tanpa konfirmasi bisnis (TIDAK ADA konversi otomatis), dan
 * menghapus `member_id` yang masih terisi berarti membuang riwayat kunjungan.
 * Bila kelak ada data di produksi, migrasi ini harus dihentikan dan kasusnya
 * dikonfirmasi lebih dulu, bukan ditekan agar "berhasil".
 *
 * Foreign key memakai restrictOnDelete, BUKAN cascade: menghapus nasabah tidak
 * boleh menghapus riwayat kunjungan yang merujuknya secara diam-diam.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('marketing_activities') && DB::table('marketing_activities')->count() > 0) {
            throw new RuntimeException(
                'Ticket 71: marketing_activities masih berisi data, sehingga penghapusan member_id '
                .'berisiko membuang riwayat kunjungan. Konversi anggota koperasi menjadi nasabah SHM '
                .'TIDAK boleh dilakukan otomatis — konfirmasi bisnis diperlukan sebelum migrasi ini '
                .'dijalankan.'
            );
        }

        // Indeks member_id dibuang lebih dulu.
        //
        // SQLite menolak DROP COLUMN selama masih ada indeks yang menunjuk
        // kolom tersebut, sehingga urutan ini diperlukan agar migrasi berjalan
        // sama di PostgreSQL (produksi) maupun SQLite (test suite).
        Schema::table('marketing_activities', function (Blueprint $table) {
            $table->dropIndex(['member_id']);
        });

        Schema::table('marketing_activities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('member_id');
        });

        Schema::table('marketing_activities', function (Blueprint $table) {
            $table->foreignId('shm_customer_id')
                ->constrained('shm_customers')
                ->restrictOnDelete();

            $table->index('shm_customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_activities', function (Blueprint $table) {
            $table->dropIndex(['shm_customer_id']);
        });

        Schema::table('marketing_activities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shm_customer_id');

            // Kolom lama dikembalikan NULLABLE: baris yang dibuat setelah
            // migrasi ini hanya memiliki shm_customer_id dan tidak dapat
            // di-backfill menjadi anggota koperasi tanpa konversi data yang
            // memerlukan konfirmasi bisnis.
            $table->foreignId('member_id')->nullable()->constrained('members')->restrictOnDelete();
        });
    }
};
