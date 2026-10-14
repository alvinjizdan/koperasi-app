<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 77 — persistence foto jaminan pada Pengajuan Pinjaman SHM.
 *
 * Kolom `photo_path` bersifat ADDITIVE dan nullable: jaminan lama tanpa foto
 * tetap valid (AC-13/AC-14) dan tidak ada backfill apa pun.
 *
 * Yang disimpan adalah PATH RELATIF pada Laravel filesystem (disk `local`,
 * privat — di bawah storage/app/private), BUKAN binary berkas dan bukan
 * absolute path. Path publik tidak pernah diekspos ke frontend: foto
 * disajikan lewat route terotorisasi `shm.loans.pengajuan.jaminan.foto`
 * yang menerapkan aturan kepemilikan pengajuan SHM yang sama.
 *
 * `collateral_type` dan `binding_status` TIDAK disentuh: master resminya
 * masih PENDING BUSINESS CONFIRMATION dan ticket ini tidak membuat daftar
 * nilai buatan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shm_loan_application_collaterals', function (Blueprint $table) {
            // Path relatif pada disk privat `local`, nullable tanpa default.
            $table->string('photo_path', 500)->nullable()->after('rights_value');
        });
    }

    public function down(): void
    {
        Schema::table('shm_loan_application_collaterals', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });
    }
};
