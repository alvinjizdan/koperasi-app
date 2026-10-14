<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 92 — Collection Case SHM (persistence + lifecycle Collection).
 *
 * ADDITIVE ONLY: tabel baru — tidak ada tabel finansial/workflow lama yang
 * diubah, dikurangi, atau ditulis ulang (shm_loans, shm_loan_installments,
 * shm_loan_payments, shm_loan_payment_allocations, shm_loan_settlements tetap
 * utuh; kolom status pinjaman/angsuran tidak disentuh).
 *
 * Struktur:
 * - `shm_loan_id` → shm_loans, RESTRICT (riwayat case tidak hilang saat
 *   pinjaman dihapus) — SATU case mewakili penanganan overdue SATU pinjaman.
 * - `marketing_id` → users, RESTRICT — initial owner = Marketing sesuai
 *   contract T91 Rule 1; resolusi owner dari `shm_loan_applications.marketing_id`
 *   dilakukan service (TIDAK ada kolom collector_id, TIDAK ada role Collector).
 * - `status` default `OPEN` (OPEN | IN_PROGRESS | PROMISE_TO_PAY | ESCALATED |
 *   RESOLVED — persis contract values T91). Status Collection TERPISAH dari
 *   `shm_loans.status`.
 * - `opened_at` terisi saat case dibuat; `resolved_at` HANYA terisi ketika
 *   status RESOLVED dan tetap null untuk status lain (keluar dari RESOLVED
 *   tidak dimungkinkan di T92).
 * - `created_by` → users, RESTRICT — pelaku pembuatan (konvensi audit T89).
 *
 * Invarian: SATU active case per pinjaman (definisi active = status selain
 * RESOLVED) lewat partial unique index — lapisan database dari guard row lock
 * di ShmCollectionCaseService. Case RESOLVED historis tidak ikut diindex,
 * sehingga pinjaman yang sah kembali eligible dapat membuka case BARU tanpa
 * konflik dengan riwayat.
 *
 * Indeks lain sengaja tidak ditambahkan: query T92 (lookup active case per
 * pinjaman) sudah dilayani sepenuhnya oleh partial unique index di atas.
 *
 * Dampak data eksisting: TABEL BARU — nol baris baru, nol perubahan data lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shm_collection_cases', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shm_loan_id')
                ->constrained('shm_loans')
                ->restrictOnDelete();

            // Initial owner (Marketing). Kolom wajib diisi — pembuatan case
            // tanpa Marketing owner yang valid DITOLAK di service (tanpa
            // fallback user pertama / marketing acak / auto-create user).
            $table->foreignId('marketing_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('status', 20)->default('OPEN');

            $table->timestamp('opened_at');
            $table->timestamp('resolved_at')->nullable();

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();
        });

        // Satu active case per pinjaman — partial unique index (didukung
        // PostgreSQL & SQLite, mengikuti pola DB::statement pada migrasi kasir
        // & jurnal). Baris RESOLVED tidak ikut diindex sehingga case historis
        // tidak memblokir case baru berikutnya.
        DB::statement(
            "CREATE UNIQUE INDEX shm_collection_cases_active_unique ON shm_collection_cases (shm_loan_id) WHERE status <> 'RESOLVED'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('shm_collection_cases');
    }
};
