<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 64 — master produk Pinjaman SHM.
 *
 * TERPISAH dari master pinjaman koperasi (`saving_products` / produk Loans):
 * SHM adalah domain finansial sendiri dan tidak membaca maupun menulis master
 * koperasi. Tidak ada kolom rate di tabel ini: rate resmi kini dihitung dari
 * tier rate produk (f(produk, tenor) — T82, ShmProductRateService), sementara
 * periodisasi & formula mengikuti baseline resmi T79 (flat per bulan) yang
 * diterapkan di level pinjaman, bukan di master produk.
 *
 * `code` UNIQUE dibuat server-side sehingga master tidak duplikat tanpa
 * tergantung pada kehati-hatian UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shm_loan_products', function (Blueprint $table) {
            $table->id();

            // Kode produk, dibuat server-side. UNIQUE mencegah master ganda.
            $table->string('code', 30)->unique();

            $table->string('name', 120);

            $table->text('description')->nullable();

            // Nonaktif bukan berarti dihapus: riwayat pengajuan/pinjaman tetap
            // merujuk ke baris ini.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shm_loan_products');
    }
};
