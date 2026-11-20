<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * TICKET 42A — Hardening SHM Settlement Refund:
     * - BR-42A-01: Explicit recovery fields (recovery_pending, recovered).
     * - BR-42A-04: Structured payout evidence fields (recipient_name, bank transfer details, timestamps).
     * - BR-42A-07: Cash outflow transaction cross-reference link.
     */
    public function up(): void
    {
        Schema::table('shm_settlement_refunds', function (Blueprint $table) {
            // BR-42A-04: Structured Payout Evidence
            $table->string('recipient_name', 150)->nullable()->after('payout_evidence_path');
            $table->string('destination_bank', 100)->nullable()->after('recipient_name');
            $table->string('destination_account_number', 50)->nullable()->after('destination_bank');
            $table->string('destination_account_holder', 150)->nullable()->after('destination_account_number');

            // BR-42A-01: Safe Recovery Lifecycle Fields (paid -> recovery_pending -> recovered)
            $table->foreignId('recovery_requested_by')->nullable()->constrained('users')->nullOnDelete()->after('destination_account_holder');
            $table->timestamp('recovery_requested_at')->nullable()->after('recovery_requested_by');
            $table->text('recovery_request_reason')->nullable()->after('recovery_requested_at');

            $table->string('recovery_reference', 100)->nullable()->after('recovery_request_reason');
            $table->decimal('recovered_amount', 18, 2)->default(0)->after('recovery_reference');
            $table->string('recovery_method', 30)->nullable()->after('recovered_amount'); // cash, bank_transfer
            $table->string('recovery_evidence_path')->nullable()->after('recovery_method');
            $table->foreignId('recovered_by')->nullable()->constrained('users')->nullOnDelete()->after('recovery_evidence_path');
            $table->timestamp('recovered_at')->nullable()->after('recovered_by');
            $table->text('recovery_notes')->nullable()->after('recovered_at');

            // BR-42A-07: Operational cross-reference to ShmCashTransaction
            $table->foreignId('shm_cash_transaction_id')->nullable()->constrained('shm_cash_transactions')->nullOnDelete()->after('recovery_notes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shm_settlement_refunds', function (Blueprint $table) {
            $table->dropForeign(['shm_cash_transaction_id']);
            $table->dropForeign(['recovery_requested_by']);
            $table->dropForeign(['recovered_by']);

            $table->dropColumn([
                'recipient_name',
                'destination_bank',
                'destination_account_number',
                'destination_account_holder',
                'recovery_requested_by',
                'recovery_requested_at',
                'recovery_request_reason',
                'recovery_reference',
                'recovered_amount',
                'recovery_method',
                'recovery_evidence_path',
                'recovered_by',
                'recovered_at',
                'recovery_notes',
                'shm_cash_transaction_id',
            ]);
        });
    }
};
