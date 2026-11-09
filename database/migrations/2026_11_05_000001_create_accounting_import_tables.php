<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Batch metadata tracking for journal & report imports
        if (! Schema::hasTable('accounting_import_batches')) {
            Schema::create('accounting_import_batches', function (Blueprint $table) {
                $table->id();
                $table->string('accounting_context', 20); // 'SHM' | 'KOPERASI'
                $table->string('import_type', 30); // 'journal' | 'report'
                $table->string('report_type', 50)->nullable(); // 'balance_sheet', 'period_profit_loss', 'cumulative_profit_loss', 'trial_balance', 'daily_journal'
                $table->string('filename', 255);
                $table->string('checksum_hash', 64);
                $table->foreignId('imported_by')->constrained('users')->restrictOnDelete();
                $table->unsignedInteger('row_count')->default(0);
                $table->unsignedInteger('success_count')->default(0);
                $table->unsignedInteger('failed_count')->default(0);
                $table->string('status', 20)->default('COMPLETED'); // 'COMPLETED' | 'FAILED'
                $table->date('period_start')->nullable();
                $table->date('period_end')->nullable();
                $table->json('summary_meta')->nullable();
                $table->timestamps();

                $table->index(['accounting_context', 'import_type']);
                $table->index(['checksum_hash', 'accounting_context']);
            });
        }

        // 2. Link journal entries to import batches (optional)
        if (! Schema::hasColumn('journal_entries', 'import_batch_id')) {
            Schema::table('journal_entries', function (Blueprint $table) {
                $table->foreignId('import_batch_id')->nullable()->constrained('accounting_import_batches')->nullOnDelete();
            });
        }

        // 3. Table for imported reports (isolated from ledger / journal_lines)
        if (! Schema::hasTable('accounting_imported_reports')) {
            Schema::create('accounting_imported_reports', function (Blueprint $table) {
                $table->id();
                $table->foreignId('batch_id')->constrained('accounting_import_batches')->cascadeOnDelete();
                $table->string('accounting_context', 20); // 'SHM'
                $table->string('report_type', 50); // 'balance_sheet', 'period_profit_loss', 'cumulative_profit_loss', 'trial_balance', 'daily_journal'
                $table->date('report_date');
                $table->date('period_start')->nullable();
                $table->date('period_end')->nullable();
                $table->string('section', 100)->nullable();
                $table->string('account_code', 50)->nullable();
                $table->string('account_name', 255)->nullable();
                $table->decimal('debit', 15, 2)->default(0);
                $table->decimal('credit', 15, 2)->default(0);
                $table->decimal('amount', 15, 2)->default(0);
                $table->string('description', 255)->nullable();
                $table->json('extra_attributes')->nullable();
                $table->unsignedInteger('row_index')->default(0);
                $table->timestamps();

                $table->index(['accounting_context', 'report_type', 'report_date'], 'imported_reports_lookup_idx');
                $table->index('batch_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounting_imported_reports');

        if (Schema::hasColumn('journal_entries', 'import_batch_id')) {
            Schema::table('journal_entries', function (Blueprint $table) {
                $table->dropForeign(['import_batch_id']);
                $table->dropColumn('import_batch_id');
            });
        }

        Schema::dropIfExists('accounting_import_batches');
    }
};
