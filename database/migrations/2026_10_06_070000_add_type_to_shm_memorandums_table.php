<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 49 — jenis memorandum: SHM/SHGB atau Buyback.
 *
 * `type` hanyalah PEMBEDA TEKNIS kategori naskah memorandum. Ini BUKAN
 * kategori legal dan BUKAN aturan finansial: tidak ada kolom nominal, harga,
 * margin, atau formula buyback yang ditambahkan. Aturan buyback (harga, nilai
 * pelunasan, keuntungan) tetap PENDING BUSINESS DECISION (lihat config/shm.php).
 *
 * Kolom dibuat nullable karena baris memorandum yang sudah ada (dari Ticket 44)
 * belum memiliki kategori dan tidak boleh dikarang nilainya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shm_memorandums', function (Blueprint $table) {
            $table->enum('type', ['shm_shgb', 'buyback'])
                ->nullable()
                ->after('id');

            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::table('shm_memorandums', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropColumn('type');
        });
    }
};
