<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 8 — Riwayat Audit Workflow Approval Memorandum (shm_memorandum_approval_histories).
 *
 * Menyimpan catatan permanen (append-only) setiap aksi workflow approval:
 * - submitted: Pengajuan ke pipeline approval
 * - review_started: Reviewer mulai memeriksa
 * - approved: Persetujuan final
 * - rejected: Penolakan (kembali ke draft, wajib alasan)
 * - resubmitted: Pengajuan ulang setelah perbaikan
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shm_memorandum_approval_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shm_memorandum_id')->constrained('shm_memorandums')->cascadeOnDelete();
            $table->string('action'); // submitted, review_started, approved, rejected, resubmitted
            $table->string('from_status'); // draft, submitted, under_review, approved
            $table->string('to_status'); // draft, submitted, under_review, approved
            $table->foreignId('performed_by')->constrained('users')->cascadeOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['shm_memorandum_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shm_memorandum_approval_histories');
    }
};
