<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah kolom `document_path` dan `document_name` pada jaminan pinjaman SHM.
 *
 * Menyimpan path relatif berkas dokumen PDF jaminan pada disk privat Laravel ('local'),
 * serta nama asli berkas untuk kemudahan visualisasi pada UI.
 * Bersifat ADDITIVE, nullable, dan backward-compatible.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shm_loan_application_collaterals', function (Blueprint $table) {
            $table->string('document_path', 500)->nullable()->after('photo_path');
            $table->string('document_name', 255)->nullable()->after('document_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shm_loan_application_collaterals', function (Blueprint $table) {
            $table->dropColumn(['document_path', 'document_name']);
        });
    }
};
