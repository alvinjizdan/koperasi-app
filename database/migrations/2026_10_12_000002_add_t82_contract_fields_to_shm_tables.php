<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 82 — kolom kontrak produk & first due date Pinjaman SHM.
 *
 * 1. `shm_loan_products.category` (PERUMAHAN / PERKAMPUNGAN / RO) — master
 *    produk kini menjadi source of truth kategori; nullable agar master
 *    historis/factory lama tidak rusak, tetapi SELURUH produk resmi yang
 *    diseed T82 selalu terisi.
 * 2. `shm_loan_products.eligibility_summary` — ringkasan syarat produk
 *    (teks). Enforcement eligibility (kolektabilitas, pastdue, plafon)
 *    tetap PENDING BUSINESS CONFIRMATION karena datanya belum ada di
 *    sistem; T82 HANYA mencatat & menampilkan syarat, tidak menebak
 *    perhitungannya.
 * 3. `shm_loan_applications.first_due_date` — tanggal jatuh tempo pertama
 *    yang DIPILIH MARKETING (bukan hasil hitung otomatis). Nullable pada
 *    schema; wajib diisi sebelum pencairan (gate ada di
 *    ShmLoanDisbursementService).
 * 4. `shm_loans.first_due_date` — nilai yang dibawa dari pengajuan yang
 *    disetujui saat pencairan. Nullable agar pinjaman historis TIDAK
 *    di-backfill (immutable).
 *
 * Kolom tanggal bertipe `date` (bukan datetime) sesuai kontrak T82.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shm_loan_products', function (Blueprint $table) {
            $table->string('category', 30)->nullable()->after('name');
            $table->text('eligibility_summary')->nullable()->after('description');
        });

        Schema::table('shm_loan_applications', function (Blueprint $table) {
            $table->date('first_due_date')->nullable()->after('tenor_months');
        });

        Schema::table('shm_loans', function (Blueprint $table) {
            $table->date('first_due_date')->nullable()->after('tenor_months');
        });
    }

    public function down(): void
    {
        Schema::table('shm_loan_products', function (Blueprint $table) {
            $table->dropColumn(['category', 'eligibility_summary']);
        });

        Schema::table('shm_loan_applications', function (Blueprint $table) {
            $table->dropColumn('first_due_date');
        });

        Schema::table('shm_loans', function (Blueprint $table) {
            $table->dropColumn('first_due_date');
        });
    }
};
