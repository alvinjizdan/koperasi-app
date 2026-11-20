<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * TICKET 42B — BR-42B-05: Multi-Transaction Partial Recovery.
     * Preserves immutable transaction history for each physical return of funds.
     */
    public function up(): void
    {
        Schema::create('shm_settlement_refund_recoveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shm_settlement_refund_id')
                ->constrained('shm_settlement_refunds')
                ->restrictOnDelete();
            $table->foreignId('shm_loan_settlement_id')
                ->constrained('shm_loan_settlements')
                ->restrictOnDelete();

            $table->string('recovery_reference', 50)->unique();
            $table->decimal('amount', 18, 2);
            $table->string('recovery_method', 30); // cash, bank_transfer
            $table->string('recovery_reference_external', 100)->nullable();
            $table->string('evidence_path', 255)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('pending'); // pending, confirmed, rejected, cancelled

            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('requested_at')->useCurrent();
            $table->text('request_reason')->nullable();

            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();

            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->string('idempotency_key', 100)->nullable()->unique();

            $table->timestamps();

            $table->index(['shm_settlement_refund_id', 'status']);
            $table->index(['shm_loan_settlement_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shm_settlement_refund_recoveries');
    }
};
