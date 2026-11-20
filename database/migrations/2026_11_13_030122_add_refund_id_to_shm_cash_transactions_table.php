<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * TICKET 42B — BR-42B-03: Cash Outflow Integration with Refund Payouts.
     */
    public function up(): void
    {
        Schema::table('shm_cash_transactions', function (Blueprint $table) {
            $table->foreignId('shm_settlement_refund_id')
                ->nullable()
                ->after('account_id')
                ->constrained('shm_settlement_refunds')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shm_cash_transactions', function (Blueprint $table) {
            $table->dropForeign(['shm_settlement_refund_id']);
            $table->dropColumn('shm_settlement_refund_id');
        });
    }
};
