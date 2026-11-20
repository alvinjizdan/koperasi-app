<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Ticket CC-04: Tabel child revision riwayat pengajuan memorandum (Option A).
     */
    public function up(): void
    {
        Schema::create('shm_memorandum_submissions', function (Blueprint $table) {
            $table->id();

            // Relasi ke parent memorandum; penghapusan parent dibatasi (RESTRICT) untuk integritas audit
            $table->foreignId('shm_memorandum_id')
                ->constrained('shm_memorandums')
                ->restrictOnDelete();

            $table->integer('revision_number');
            $table->string('memorandum_type', 32);

            $table->foreignId('submitted_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('submitted_by_name', 255);
            $table->timestampTz('submitted_at');

            $table->text('narrative_notes')->nullable();
            $table->text('risk_mitigation_notes')->nullable();

            $table->date('shgb_start_date')->nullable();
            $table->date('shgb_end_date')->nullable();

            // Snapshot payload konteks dokumen
            $table->jsonb('borrower_snapshot');
            $table->jsonb('collateral_snapshot');
            $table->jsonb('buyback_documents')->nullable();
            $table->jsonb('credit_check_snapshot');
            $table->jsonb('exception_snapshot')->nullable();

            $table->string('template_version', 32)->default('v1.0');
            $table->string('content_hash', 64); // SHA-256 canonical hash

            $table->timestampTz('created_at')->useCurrent();

            // Integritas nomor revisi per memorandum
            $table->unique(['shm_memorandum_id', 'revision_number'], 'shm_memo_sub_memo_rev_unique');
            $table->index(['shm_memorandum_id', 'created_at'], 'shm_memo_sub_memo_created_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shm_memorandum_submissions');
    }
};
