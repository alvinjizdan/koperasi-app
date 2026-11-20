<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * TICKET 42 — SHM Operational Settlement Overpayment Refunds.
     */
    public function up(): void
    {
        if (! Schema::hasTable('shm_settlement_refunds')) {
            Schema::create('shm_settlement_refunds', function (Blueprint $table) {
                $table->id();
                $table->foreignId('shm_loan_settlement_id')
                    ->constrained('shm_loan_settlements')
                    ->restrictOnDelete();
                $table->foreignId('shm_loan_id')
                    ->constrained('shm_loans')
                    ->restrictOnDelete();
                $table->foreignId('shm_customer_id')
                    ->constrained('shm_customers')
                    ->restrictOnDelete();
                $table->string('refund_reference', 50)->unique();
                $table->decimal('amount', 18, 2);
                $table->string('payout_method', 30); // cash, bank_transfer
                $table->json('payout_details')->nullable();
                $table->text('reason')->nullable();
                $table->string('status', 30)->default('pending'); // pending, approved, rejected, cancelled, paid, reversed
                $table->string('idempotency_key', 100)->nullable()->unique();

                $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('requested_at')->useCurrent();

                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();

                $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('rejected_at')->nullable();
                $table->text('rejection_reason')->nullable();

                $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('cancelled_at')->nullable();
                $table->text('cancellation_reason')->nullable();

                $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('paid_at')->nullable();
                $table->string('payout_reference', 100)->nullable();
                $table->text('payout_notes')->nullable();
                $table->string('payout_evidence_path')->nullable();

                $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reversed_at')->nullable();
                $table->text('reversal_reason')->nullable();

                $table->timestamps();

                $table->index(['shm_loan_settlement_id', 'status']);
                $table->index(['shm_loan_id', 'status']);
                $table->index(['shm_customer_id', 'status']);
                $table->index('status');
                $table->index('refund_reference');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shm_settlement_refunds');
    }
};
