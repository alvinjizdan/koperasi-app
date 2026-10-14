<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 76 — Menutup gap Data Pribadi pada Pengajuan Pinjaman SHM.
 *
 * Field berikut dibutuhkan halaman Pengajuan Pinjaman dan sebelumnya ditandai
 * GAP karena belum ada pada master nasabah SHM. Semua bersifat ADDITIVE,
 * nullable, dan TIDAK melakukan backfill: nasabah lama tetap valid tanpa
 * nilai ini, dan tidak ada satu pun data koperasi yang dibaca/diubah.
 *
 * Keputusan tipe data:
 * - `nip` & `telepon_rumah` memakai string (bukan integer) sehingga leading
 *   zero dan tanda + tidak hilang — mengikuti pola `nik`/`phone` existing;
 * - `jumlah_tanggungan` integer tidak negatif;
 * - `saldo_modal`, `saldo_non_modal`, `batas_hutang` memakai decimal(18,2)
 *   — presisi uang yang sama dengan `principal` pada pinjaman SHM.
 *
 * BATASAN YANG DISENGAJA:
 * - Ketiga kolom saldo/batas hanyalah NILAI MASTER yang dimasukkan manual
 *   oleh staf. TIDAK ADA formula perhitungan, TIDAK ada koneksi ke simpanan
 *   koperasi/manapun, dan TIDAK ada credit-limit calculation. Semantik
 *   bisnis resmi (sumber nilai, kapan dihitung ulang) masih PENDING BUSINESS
 *   CONFIRMATION — jangan menambahkan kalkulasi di ticket lain tanpa
 *   keputusan KSP.
 * - Kolom finansial di master nasabah bukan bagian dari snapshot aplikasi:
 *   Pengajuan Pinjaman membaca nilai terkini lewat relasi customer, bukan
 *   menyalin ke `shm_loan_applications`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shm_customers', function (Blueprint $table) {
            // --- Field non-finansial (kontrak jelas: identitas/pekerjaan) ---
            $table->string('unit_kerja', 150)->nullable()->after('address');
            $table->string('nip', 50)->nullable()->after('unit_kerja');
            $table->string('telepon_rumah', 30)->nullable()->after('nip');
            $table->string('tempat_lahir', 100)->nullable()->after('telepon_rumah');
            $table->date('tanggal_lahir')->nullable()->after('tempat_lahir');
            $table->unsignedInteger('jumlah_tanggungan')->nullable()->after('tanggal_lahir');

            // --- Field finansial master (nilai manual, tanpa formula) ---
            $table->decimal('saldo_modal', 18, 2)->nullable()->after('jumlah_tanggungan');
            $table->decimal('saldo_non_modal', 18, 2)->nullable()->after('saldo_modal');
            $table->decimal('batas_hutang', 18, 2)->nullable()->after('saldo_non_modal');
        });
    }

    public function down(): void
    {
        Schema::table('shm_customers', function (Blueprint $table) {
            $table->dropColumn([
                'unit_kerja',
                'nip',
                'telepon_rumah',
                'tempat_lahir',
                'tanggal_lahir',
                'jumlah_tanggungan',
                'saldo_modal',
                'saldo_non_modal',
                'batas_hutang',
            ]);
        });
    }
};
