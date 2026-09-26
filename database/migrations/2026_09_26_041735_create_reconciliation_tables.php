<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Header proses rekonsiliasi: satu baris per eksekusi pemeriksaan.
        Schema::create('reconciliation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_number', 40)->unique();
            $table->date('period_start');
            $table->date('period_end');
            // Modul yang diperiksa: savings | loans | cash (JSON array).
            $table->json('modules');
            $table->foreignId('run_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('PENDING'); // PENDING | RUNNING | COMPLETED | FAILED
            $table->unsignedBigInteger('checked_rows')->default(0);
            $table->unsignedBigInteger('findings_count')->default(0);
            $table->text('summary')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('period_start');
            $table->index('run_by');
        });

        // Temuan rekonsiliasi: referensi ke objek sumber tanpa mengubahnya.
        Schema::create('reconciliation_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reconciliation_run_id')->constrained('reconciliation_runs')->cascadeOnDelete();
            $table->string('finding_type', 40); // BALANCE_MISMATCH | DUPLICATE_REFERENCE | MISSING_REFERENCE | INVALID_STATUS | UNALLOCATED_PAYMENT | CASH_VARIANCE | INCOMPLETE_DATA
            $table->string('module', 20); // savings | loans | cash
            $table->string('object_type', 60)->nullable();
            $table->unsignedBigInteger('object_id')->nullable();
            $table->string('object_label', 150)->nullable();
            $table->decimal('expected_value', 15, 2)->nullable();
            $table->decimal('actual_value', 15, 2)->nullable();
            $table->decimal('variance', 15, 2)->nullable();
            $table->string('priority', 10)->default('MEDIUM'); // LOW | MEDIUM | HIGH
            $table->string('status', 30)->default('OPEN'); // OPEN | UNDER_REVIEW | RESOLVED | ACCEPTED_WITH_REASON
            $table->timestamp('found_at');
            $table->timestamps();

            $table->index(['module', 'status']);
            $table->index('finding_type');
            $table->index('status');
        });

        // Riwayat tindak lanjut temuan: append-only, tidak pernah dihapus.
        Schema::create('reconciliation_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reconciliation_finding_id')->constrained('reconciliation_findings')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->text('note');
            $table->timestamp('created_at')->useCurrent();

            $table->index('reconciliation_finding_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_follow_ups');
        Schema::dropIfExists('reconciliation_findings');
        Schema::dropIfExists('reconciliation_runs');
    }
};
