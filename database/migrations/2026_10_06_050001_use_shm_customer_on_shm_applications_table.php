<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 46 — Pengajuan SHM memakai Nasabah SHM, bukan anggota koperasi.
 *
 * Sebelum ticket ini, pengajuan menunjuk ke `members` (opsional) atau ke data
 * calon nasabah (`prospect_name` / `prospect_phone`). Keputusan KSP terbaru
 * memisahkan nasabah SHM menjadi master data sendiri, sehingga:
 *
 * - `shm_customer_id` menjadi satu-satunya rujukan nasabah dan WAJIB terisi.
 *   Kolom NOT NULL dipilih supaya tidak mungkin ada pengajuan tanpa nasabah.
 * - `member_id`, `prospect_name`, dan `prospect_phone` DIHAPUS. Ketiganya
 *   divalidasi tidak terpakai sebelum migrasi ini dibuat (jumlah pengajuan
 *   SHM saat migrasi berjalan adalah 0), sehingga tidak ada data yang hilang
 *   dan tidak ada konversi otomatis anggota koperasi menjadi nasabah SHM.
 *
 * Foreign key memakai restrictOnDelete, BUKAN cascade: menghapus nasabah
 * tidak boleh menghapus pengajuan yang merujuknya secara diam-diam.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Indeks member_id dibuang lebih dulu.
        //
        // SQLite menolak DROP COLUMN selama masih ada indeks yang menunjuk
        // kolom tersebut, sehingga urutan ini diperlukan agar migrasi berjalan
        // sama di PostgreSQL (produksi) maupun SQLite (test suite).
        Schema::table('shm_applications', function (Blueprint $table) {
            $table->dropIndex(['member_id']);
        });

        // Hapus rujukan lama: foreign key beserta kolomnya.
        Schema::table('shm_applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('member_id');
            $table->dropColumn(['prospect_name', 'prospect_phone']);
        });

        Schema::table('shm_applications', function (Blueprint $table) {
            $table->foreignId('shm_customer_id')
                ->constrained('shm_customers')
                ->restrictOnDelete();

            $table->index('shm_customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('shm_applications', function (Blueprint $table) {
            $table->dropForeign(['shm_customer_id']);
            $table->dropColumn('shm_customer_id');

            $table->foreignId('member_id')->nullable()->constrained('members')->restrictOnDelete();
            $table->string('prospect_name', 150)->nullable();
            $table->string('prospect_phone', 30)->nullable();
        });
    }
};
