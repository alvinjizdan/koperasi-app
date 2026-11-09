<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 8 — Field Actor & Timestamp Approval pada Memorandum.
 *
 * Menambahkan metadata approval mandiri untuk Memorandum SHM/SHGB & Buyback:
 * - submitted_by: user pengaju
 * - reviewed_by: reviewer yang memulai review
 * - reviewed_at: waktu review dimulai
 * - approved_by: approver yang menyetujui
 * - approved_at: waktu persetujuan
 * - rejection_notes: catatan/alasan penolakan terakhir (jika pernah ditolak)
 * - rejected_at: waktu penolakan terakhir
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shm_memorandums', function (Blueprint $table) {
            $table->foreignId('submitted_by')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->after('submitted_by')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->foreignId('approved_by')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->timestamp('rejected_at')->nullable()->after('approved_at');
            $table->text('rejection_notes')->nullable()->after('rejected_at');
        });
    }

    public function down(): void
    {
        Schema::table('shm_memorandums', function (Blueprint $table) {
            $table->dropForeign(['submitted_by']);
            $table->dropForeign(['reviewed_by']);
            $table->dropForeign(['approved_by']);
            $table->dropColumn([
                'submitted_by',
                'reviewed_by',
                'reviewed_at',
                'approved_by',
                'approved_at',
                'rejected_at',
                'rejection_notes',
            ]);
        });
    }
};
