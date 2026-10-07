<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket Marketing Activity Tracking — aktivitas/kunjungan Marketing.
 *
 * Satu baris = satu kegiatan check-in Marketing ke seorang anggota.
 *
 * Batasan yang sengaja ditegakkan di level data:
 * - marketing_id dan member_id memakai restrictOnDelete agar riwayat kunjungan
 *   tidak pernah hilang atau menggantung bila data induk dihapus;
 * - checked_in_at diisi server (waktu sistem), bukan dari input pengguna;
 * - latitude/longitude boleh NULL di skema, tetapi endpoint check-in mewajibkannya
 *   sehingga lokasi tidak pernah dikarang. NULL tetap dibiarkan untuk keputusan
 *   KSP yang belum diambil (mis. check-in tanpa GPS);
 * - result BELUM berupa daftar status resmi. Isinya catatan singkat yang diisi
 *   Marketing sampai KSP menetapkan daftar hasil kunjungan yang sah;
 * - idempotency_key mencegah satu check-in yang sama tercatat berkali-kali saat
 *   pengguna menekan tombol dua kali atau me-refresh halaman.
 *
 * TIDAK ada pelacakan lokasi berkelanjutan: satu baris hanya dibuat ketika
 * Marketing melakukan check-in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_activities', function (Blueprint $table) {
            $table->id();

            // Marketing yang melakukan aktivitas. Tidak pernah berasal dari input form.
            $table->foreignId('marketing_id')->constrained('users')->restrictOnDelete();

            // Anggota/nasabah yang menjadi tujuan kunjungan.
            $table->foreignId('member_id')->constrained('members')->restrictOnDelete();

            // Waktu check-in dicatat dari waktu sistem.
            $table->timestamp('checked_in_at');

            // Lokasi Marketing saat check-in.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 11, 7)->nullable();

            // Hasil kunjungan: teks bebas, belum ada daftar status resmi.
            $table->string('result', 100)->nullable();
            $table->text('notes')->nullable();

            // Kunci idempotensi untuk mencegah check-in ganda.
            $table->string('idempotency_key', 100)->nullable()->unique();

            $table->timestamps();

            $table->index(['marketing_id', 'checked_in_at']);
            $table->index('member_id');
            $table->index('checked_in_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_activities');
    }
};
