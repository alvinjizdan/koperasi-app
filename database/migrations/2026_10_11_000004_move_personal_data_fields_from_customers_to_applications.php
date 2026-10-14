<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 76-FIX — Pindahkan Data Pribadi dari Master Nasabah ke Pengajuan Pinjaman.
 *
 * Sembilan kolom Data Pribadi (Unit Kerja, NIP, Telepon Rumah, Tempat Lahir,
 * Tanggal Lahir, Jumlah Tanggungan, Saldo Modal, Saldo Non Modal, Batas Hutang)
 * merupakan DATA PENGAJUAN (application snapshot), bukan data master nasabah
 * SHM (`shm_customers`).
 *
 * Migrasi korektif ini:
 * 1. Menambahkan sembilan kolom nullable tersebut ke `shm_loan_applications`;
 * 2. Menghapus sembilan kolom tersebut dari `shm_customers` (yang sebelumnya
 *    ditambahkan oleh migrasi T76).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shm_loan_applications', function (Blueprint $table) {
            $table->string('unit_kerja', 150)->nullable()->after('disbursement_method');
            $table->string('nip', 50)->nullable()->after('unit_kerja');
            $table->string('telepon_rumah', 30)->nullable()->after('nip');
            $table->string('tempat_lahir', 100)->nullable()->after('telepon_rumah');
            $table->date('tanggal_lahir')->nullable()->after('tempat_lahir');
            $table->unsignedInteger('jumlah_tanggungan')->nullable()->after('tanggal_lahir');
            $table->decimal('saldo_modal', 18, 2)->nullable()->after('jumlah_tanggungan');
            $table->decimal('saldo_non_modal', 18, 2)->nullable()->after('saldo_modal');
            $table->decimal('batas_hutang', 18, 2)->nullable()->after('saldo_non_modal');
        });

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

    public function down(): void
    {
        Schema::table('shm_customers', function (Blueprint $table) {
            $table->string('unit_kerja', 150)->nullable()->after('address');
            $table->string('nip', 50)->nullable()->after('unit_kerja');
            $table->string('telepon_rumah', 30)->nullable()->after('nip');
            $table->string('tempat_lahir', 100)->nullable()->after('telepon_rumah');
            $table->date('tanggal_lahir')->nullable()->after('tempat_lahir');
            $table->unsignedInteger('jumlah_tanggungan')->nullable()->after('tanggal_lahir');
            $table->decimal('saldo_modal', 18, 2)->nullable()->after('jumlah_tanggungan');
            $table->decimal('saldo_non_modal', 18, 2)->nullable()->after('saldo_modal');
            $table->decimal('batas_hutang', 18, 2)->nullable()->after('saldo_non_modal');
        });

        Schema::table('shm_loan_applications', function (Blueprint $table) {
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
