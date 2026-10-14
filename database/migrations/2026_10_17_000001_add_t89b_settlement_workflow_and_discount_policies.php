<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T89B — Settlement Completion, Custom Discount Policy & Workflow.
 *
 * ADDITIVE ONLY. Tidak ada data finansial lama yang diubah/dihapus:
 * `shm_loan_settlements` saat ini memiliki 0 baris completed (T89 tidak
 * pernah menutup settlement), sehingga perubahan index di bawah aman dan
 * tidak ada backfill — kolom baru nullable/default untuk baris legacy.
 *
 * Dua bagian:
 *
 * 1. Tabel BARU `shm_settlement_discount_policies` — master discount bunga
 *    pelunasan yang DATA-DRIVEN (rentang sisa bulan + persentase + effective
 *    dating). TIDAK ada baris default/seed: persentase adalah keputusan
 *    bisnis admin, bukan angka rekaan sistem (AC7).
 *
 * 2. Kolom ADDITIVE pada `shm_loan_settlements`:
 *    - Workflow Model B: link payment (T86) + payment_recorded_at +
 *      payment_verified_at/by — pembayaran tetap tabel `shm_loan_payments`
 *      yang sudah ada; TIDAK ada payment table baru dan TIDAK ada status
 *      baru pada payment (verification direkam di sisi settlement).
 *    - Snapshot discount: policy FK + eligible/percent/base/amount/interest
 *      after discount + remaining months — policy lama tidak pernah
 *      menghitung ulang settlement lama (AC6).
 *    - overpayment_amount — dihitung saat completion, 0 selama blocker
 *      hubungan member→Simpanan Pokok belum tersedia.
 *
 * 3. Index: UNIQUE(shm_loan_id) lama (satu baris per pinjaman) diganti
 *    partial unique HANYA untuk status 'completed' — memungkinkan
 *    pending → rejected → pengajuan ulang, sementara dua completed
 *    settlement untuk pinjaman yang sama tetap mustahil di level database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shm_settlement_discount_policies', function (Blueprint $table) {
            $table->id();

            // Rentang sisa bulan (wajib valid: min <= max — divalidasi service).
            $table->unsignedInteger('min_remaining_months');
            $table->unsignedInteger('max_remaining_months');

            // Persentase diskon 0–100; TIDAK ada default (AC7).
            $table->decimal('discount_percent', 5, 2);

            $table->boolean('is_active')->default(true);

            // Effective dating nullable = tanpa batas.
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('is_active');
        });

        Schema::table('shm_loan_settlements', function (Blueprint $table) {
            // ---- Workflow Model B (Settlement ≠ Payment) ----
            $table->foreignId('shm_loan_payment_id')
                ->nullable()
                ->after('unallocated_payment_amount')
                ->constrained('shm_loan_payments')
                ->restrictOnDelete();

            $table->dateTime('payment_recorded_at')->nullable()->after('shm_loan_payment_id');
            $table->dateTime('payment_verified_at')->nullable()->after('payment_recorded_at');

            $table->foreignId('payment_verified_by')
                ->nullable()
                ->after('payment_verified_at')
                ->constrained('users')
                ->restrictOnDelete();

            // ---- Snapshot discount (immutable — AC6) ----
            $table->foreignId('discount_policy_id')
                ->nullable()
                ->after('notes')
                ->constrained('shm_settlement_discount_policies')
                ->restrictOnDelete();

            $table->boolean('discount_eligible')->default(false)->after('discount_policy_id');
            $table->boolean('discount_applied')->default(false)->after('discount_eligible');

            $table->unsignedInteger('remaining_months')->nullable()->after('discount_applied');
            $table->decimal('discount_percent', 5, 2)->nullable()->after('remaining_months');

            $table->decimal('remaining_interest_amount', 18, 2)->default(0)->after('discount_percent');
            $table->decimal('discount_base_amount', 18, 2)->nullable()->after('remaining_interest_amount');
            $table->decimal('discount_amount', 18, 2)->nullable()->after('discount_base_amount');
            $table->decimal('interest_after_discount_amount', 18, 2)->nullable()->after('discount_amount');

            // Overpayment pembayaran pelunasan (0 selama posting ke Simpanan
            // Pokok masih BLOCKER BUSINESS DATA — lihat laporan T89B).
            $table->decimal('overpayment_amount', 18, 2)->default(0)->after('settlement_amount');
        });

        // UNIQUE(shm_loan_id) lama → partial unique hanya untuk completed.
        // Defensif terhadap drift lingkungan: constraint lama bisa berupa
        // unique constraint (bawaan create) atau index biasa (db dev lama).
        Schema::table('shm_loan_settlements', function (Blueprint $table) {
            if (Schema::hasIndex('shm_loan_settlements', 'shm_loan_settlements_shm_loan_id_unique')) {
                $table->dropUnique('shm_loan_settlements_shm_loan_id_unique');
            }

            if (Schema::hasIndex('shm_loan_settlements', 'shm_loan_settlements_shm_loan_id_index')) {
                $table->dropIndex('shm_loan_settlements_shm_loan_id_index');
            }
        });

        // Literal konstanta (bukan parameter terikat): SQLite menolak bound
        // parameter pada WHERE clause partial index; 'completed' adalah
        // konstanta kontraktual, bukan input pengguna.
        DB::statement(
            'CREATE UNIQUE INDEX shm_loan_settlements_completed_unique '
            ."ON shm_loan_settlements (shm_loan_id) WHERE status = 'completed'",
        );
    }

    public function down(): void
    {
        DB::statement(
            'DROP INDEX IF EXISTS shm_loan_settlements_completed_unique',
        );

        Schema::table('shm_loan_settlements', function (Blueprint $table) {
            $table->unique('shm_loan_id');

            if (Schema::hasColumn('shm_loan_settlements', 'discount_policy_id')) {
                $table->dropConstrainedForeignId('discount_policy_id');
                $table->dropColumn([
                    'discount_eligible', 'discount_applied', 'remaining_months',
                    'discount_percent', 'remaining_interest_amount',
                    'discount_base_amount', 'discount_amount',
                    'interest_after_discount_amount', 'overpayment_amount',
                ]);
            }

            if (Schema::hasColumn('shm_loan_settlements', 'payment_verified_by')) {
                $table->dropConstrainedForeignId('payment_verified_by');
                $table->dropColumn(['payment_verified_at', 'payment_recorded_at']);
            }

            if (Schema::hasColumn('shm_loan_settlements', 'shm_loan_payment_id')) {
                $table->dropConstrainedForeignId('shm_loan_payment_id');
            }
        });

        Schema::dropIfExists('shm_settlement_discount_policies');
    }
};
