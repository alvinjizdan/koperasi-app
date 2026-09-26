<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indeks tambahan untuk pola query dashboard dan laporan.
 *
 * Setiap indeks dibuat berdasarkan pola filter nyata pada modul laporan:
 * periode transaksi, status transaksi, dan tanggal jatuh tempo angsuran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->index('joined_at', 'members_joined_at_index');
        });

        Schema::table('savings_transactions', function (Blueprint $table) {
            $table->index(['transaction_date', 'type', 'status'], 'savings_tx_period_type_status_index');
        });

        Schema::table('loans', function (Blueprint $table) {
            $table->index(['status', 'submission_date'], 'loans_status_submission_index');
            $table->index(['status', 'disbursed_at'], 'loans_status_disbursed_index');
        });

        Schema::table('installment_payments', function (Blueprint $table) {
            $table->index(['status', 'payment_date'], 'installment_payments_status_date_index');
            $table->index(['payment_date', 'processed_by'], 'installment_payments_date_operator_index');
        });

        Schema::table('installment_schedules', function (Blueprint $table) {
            $table->index(['status', 'due_date'], 'installment_schedules_status_due_index');
            $table->index(['due_date', 'remaining_amount'], 'installment_schedules_due_remaining_index');
        });

        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->index(['transaction_date', 'type', 'status'], 'cash_tx_period_type_status_index');
        });

        Schema::table('cashier_sessions', function (Blueprint $table) {
            $table->index(['operational_date', 'status'], 'cashier_sessions_date_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropIndex('members_joined_at_index');
        });

        Schema::table('savings_transactions', function (Blueprint $table) {
            $table->dropIndex('savings_tx_period_type_status_index');
        });

        Schema::table('loans', function (Blueprint $table) {
            $table->dropIndex('loans_status_submission_index');
            $table->dropIndex('loans_status_disbursed_index');
        });

        Schema::table('installment_payments', function (Blueprint $table) {
            $table->dropIndex('installment_payments_status_date_index');
            $table->dropIndex('installment_payments_date_operator_index');
        });

        Schema::table('installment_schedules', function (Blueprint $table) {
            $table->dropIndex('installment_schedules_status_due_index');
            $table->dropIndex('installment_schedules_due_remaining_index');
        });

        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropIndex('cash_tx_period_type_status_index');
        });

        Schema::table('cashier_sessions', function (Blueprint $table) {
            $table->dropIndex('cashier_sessions_date_status_index');
        });
    }
};
