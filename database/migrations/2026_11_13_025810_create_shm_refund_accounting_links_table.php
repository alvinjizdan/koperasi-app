<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * TICKET 42B — BR-42B-01 & BR-42B-02: Structured Payout / Accounting Linkage & Idempotency.
     * Prevents duplicate accounting recognition across Cash Outflow, Manual Journal, and XLSX Import Center.
     */
    public function up(): void
    {
        Schema::create('shm_refund_accounting_links', function (Blueprint $table) {
            $table->id();
            $table->string('accounting_context', 20)->default('shm');
            $table->foreignId('shm_settlement_refund_id')
                ->constrained('shm_settlement_refunds')
                ->restrictOnDelete();

            $table->string('recognition_channel', 30); // 'cash_outflow', 'manual_journal', 'xlsx_import'

            // Optional direct link to ShmCashTransaction if recognized through cash outflow
            $table->foreignId('shm_cash_transaction_id')
                ->nullable()
                ->constrained('shm_cash_transactions')
                ->nullOnDelete();

            // Link to JournalEntry
            $table->foreignId('journal_entry_id')
                ->constrained('journal_entries')
                ->restrictOnDelete();

            $table->string('status', 20)->default('POSTED'); // 'DRAFT', 'POSTED', 'REVERSED'

            $table->foreignId('linked_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('linked_at')->useCurrent();
            $table->text('notes')->nullable();

            $table->timestamps();

            // Composite indexes for lookup & uniqueness
            $table->index(['shm_settlement_refund_id', 'status']);
            $table->index(['journal_entry_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shm_refund_accounting_links');
    }
};
