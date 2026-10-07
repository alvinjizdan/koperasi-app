<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket Marketing Activity Tracking — titik lokasi anggota/nasabah.
 *
 * Koordinat adalah informasi yang BERBEDA dari alamat (address). Kolom address
 * tidak disentuh dan tidak diganti: alamat tetap berupa teks yang dibaca manusia,
 * sementara latitude/longitude menyimpan titik lokasi untuk kebutuhan kunjungan.
 *
 * Kedua kolom boleh kosong. Tidak ada koordinat default maupun koordinat contoh:
 * lokasi yang belum diketahui harus tetap kosong, bukan diisi asumsi.
 *
 * Desimal (bukan float) dipakai agar pembulatan tidak menggeser koordinat saat
 * disimpan dan dibaca kembali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            // Rentang latitude -90..90 memerlukan 3 digit sebelum koma,
            // longitude -180..180 memerlukan 4 digit sebelum koma.
            $table->decimal('latitude', 10, 7)->nullable()->after('address');
            $table->decimal('longitude', 11, 7)->nullable()->after('latitude');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
