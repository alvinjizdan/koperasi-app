<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T158 / T004 — Penanda durable pelepasan pembayaran settlement ke domain reguler (T89B replacement).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('shm_loan_payments', 'settlement_released_at')) {
            Schema::table('shm_loan_payments', function (Blueprint $table) {
                $table->timestamp('settlement_released_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('shm_loan_payments', 'settlement_released_at')) {
            Schema::table('shm_loan_payments', function (Blueprint $table) {
                $table->dropColumn('settlement_released_at');
            });
        }
    }
};
