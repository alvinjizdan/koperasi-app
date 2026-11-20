<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * TICKET 41 — SHM Settlement Unallocated Payment Tracking & Usage Table.
     * 1. Menambahkan kolom applied_unallocated_amount dan net_cash_due pada shm_loan_settlements.
     * 2. Membuat tabel shm_loan_settlement_unallocated_usages untuk mencatat audit trail
     *    konsumsi saldo titipan angsuran per transaksi pembayaran sumber.
     */
    public function up(): void
    {
        if (Schema::hasTable('shm_loan_settlements')) {
            Schema::table('shm_loan_settlements', function (Blueprint $table) {
                if (! Schema::hasColumn('shm_loan_settlements', 'applied_unallocated_amount')) {
                    $table->decimal('applied_unallocated_amount', 18, 2)->default(0)->after('unallocated_payment_amount');
                }
                if (! Schema::hasColumn('shm_loan_settlements', 'net_cash_due')) {
                    $table->decimal('net_cash_due', 18, 2)->default(0)->after('settlement_amount');
                }
            });
        }

        if (! Schema::hasTable('shm_loan_settlement_unallocated_usages')) {
            Schema::create('shm_loan_settlement_unallocated_usages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('shm_loan_settlement_id')
                    ->constrained('shm_loan_settlements')
                    ->cascadeOnDelete();
                $table->foreignId('shm_loan_payment_id')
                    ->constrained('shm_loan_payments')
                    ->restrictOnDelete();
                $table->decimal('amount', 18, 2);
                $table->string('status', 30)->default('active'); // active, released, reversed
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['shm_loan_settlement_id', 'status']);
                $table->index(['shm_loan_payment_id', 'status']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shm_loan_settlement_unallocated_usages');

        if (Schema::hasTable('shm_loan_settlements')) {
            Schema::table('shm_loan_settlements', function (Blueprint $table) {
                if (Schema::hasColumn('shm_loan_settlements', 'applied_unallocated_amount')) {
                    $table->dropColumn('applied_unallocated_amount');
                }
                if (Schema::hasColumn('shm_loan_settlements', 'net_cash_due')) {
                    $table->dropColumn('net_cash_due');
                }
            });
        }
    }
};
