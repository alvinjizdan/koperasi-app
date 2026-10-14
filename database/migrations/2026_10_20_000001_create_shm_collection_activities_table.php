<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 93 — tabel Collection Activity (additive; tanpa perubahan tabel existing).
 *
 * Keputusan skema:
 * - `shm_collection_case_id` wajib — aktivitas selalu melekat pada case
 *   (TIDAK ada jalur langsung ke pinjaman) dengan RESTRICT agar riwayat
 *   operasional tidak terhapus ikut case (konsisten dengan histori finansial);
 * - `user_id` = actor pencatat (users existing; RESTRICT — identitas tidak
 *   boleh lenyap bersama catatan aktivitas);
 * - `activity_at` timestamp wajib — waktu aktivitas SEBENARNYA dan boleh
 *   berbeda dari `created_at` (waktu pencatatan); tanpa default server time;
 * - `notes`/`next_action` nullable text — deskriptif operasional, tanpa
 *   panjang maksimum bisnis (batas validasi hidup di lapisan request);
 * - indeks `(shm_collection_case_id, activity_at)` — satu-satunya pola query
 *   nyata (riwayat per case, terurut waktu); kolom pertama indeks juga
 *   melayani lookup FK case, sehingga tidak perlu indeks terpisah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shm_collection_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shm_collection_case_id')
                ->constrained('shm_collection_cases')
                ->restrictOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->string('activity_type', 20);
            $table->string('result', 20);
            $table->timestamp('activity_at');
            $table->text('notes')->nullable();
            $table->text('next_action')->nullable();
            $table->timestamps();

            $table->index(['shm_collection_case_id', 'activity_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shm_collection_activities');
    }
};
