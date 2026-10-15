<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 97 — tabel record Promise to Pay (PTP) Collection.
 *
 * PTP adalah entitas persisten TERPISAH dari Collection Case (status
 * workflow) dan dari Collection Activity (riwayat komunikasi): satu record
 * = satu janji bayar konkret (tanggal + nominal + catatan) yang dibuat
 * PEMILIK case selama case berstatus PROMISE_TO_PAY.
 *
 * Kolom:
 * - `shm_collection_case_id` → users? — induk wajib, RESTRICT (record PTP
 *   tidak pernah exist tanpa case; mengikuti pola FK activities/cases).
 * - `created_by` → users — pelaku pembuatan selalu user session (konvensi
 *   audit T89; frontend tidak pernah memasok actor).
 * - `promised_date` date — tanggal janji pembayaran (kalender, bukan
 *   timestamp: kontrak T91 Rule 8 hanya mendefinisikan "tanggal janji").
 * - `promised_amount` decimal(18,2) — konvensi finansial SHM (bukan FLOAT).
 * - `status` OPEN | FULFILLED | MISSED | CANCELLED — state model minimal
 *   T97; MISSED/FULFILLED HANYA lewat aksi manual eksplisit (TIDAK pernah
 *   dihitung otomatis dari tanggal atau pembayaran — contract `cannot_guess`).
 * - `notes` text nullable — catatan deskriptif (500 karakter divalidasi di
 *   Form Request, kolom memakai text mengikuti notes activities).
 *
 * Invarian: SATU PTP OPEN per case lewat partial unique index — lapisan
 * database dari guard duplikat di ShmCollectionPtpService. Record terminal
 * (FULFILLED/MISSED/CANCELLED) tidak ikut diindex sehingga riwayat PTP
 * berikutnya dapat dibuat tanpa konflik.
 *
 * Dampak data eksisting: TABEL BARU — nol baris baru, nol perubahan data
 * lama. Tidak ada kolom yang ditambahkan ke tabel existing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shm_collection_ptps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shm_collection_case_id')
                ->constrained('shm_collection_cases')
                ->restrictOnDelete();
            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();
            $table->date('promised_date');
            $table->decimal('promised_amount', 18, 2);
            $table->string('status', 20)->default('OPEN');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['shm_collection_case_id', 'status']);
        });

        // Satu PTP OPEN per case — partial unique index (didukung
        // PostgreSQL & SQLite, mengikuti pola DB::statement pada migrasi
        // Collection Case). Baris terminal tidak ikut diindex sehingga
        // riwayat PTP lama tidak memblokir PTP baru berikutnya.
        DB::statement(
            "CREATE UNIQUE INDEX shm_collection_ptps_open_unique ON shm_collection_ptps (shm_collection_case_id) WHERE status = 'OPEN'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('shm_collection_ptps');
    }
};
