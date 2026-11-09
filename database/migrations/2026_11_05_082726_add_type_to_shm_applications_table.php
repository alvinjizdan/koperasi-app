<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 3 — SHM Memorandum: Multi-Type Application (SHGB & Buyback).
 *
 * Menambahkan kolom `type` pada tabel `shm_applications` untuk membedakan
 * pengajuan SHGB dan Buyback.
 *
 * Aturan migrasi:
 * - Bersifat additive.
 * - Nilai default 'shgb'.
 * - Seluruh record historis yang sudah ada otomatis bernilai 'shgb' (backfill).
 * - Menambahkan index pada kolom `type`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shm_applications', function (Blueprint $table) {
            $table->string('type', 20)
                ->default('shgb')
                ->after('application_number');

            $table->index('type');
        });

        // Pastikan seluruh data historis memiliki nilai 'shgb'
        DB::table('shm_applications')
            ->whereNull('type')
            ->orWhere('type', '')
            ->update(['type' => 'shgb']);
    }

    public function down(): void
    {
        Schema::table('shm_applications', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropColumn('type');
        });
    }
};
