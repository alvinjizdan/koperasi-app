<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 15 — mekanisme koreksi transaksi simpanan yang teraudit.
 *
 * Transaksi sukses tidak boleh diubah/dihapus langsung (aturan A.7);
 * koreksi melalui transaksi penyesuaian (reversal) yang mereferensikan
 * transaksi asal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('savings_transactions', function (Blueprint $table) {
            $table->foreignId('reversal_of_id')->nullable()->constrained('savings_transactions')->nullOnDelete()->after('idempotency_key');
            $table->string('entry_type', 20)->default('original')->after('reversal_of_id'); // original | reversal
            $table->index('reversal_of_id');
        });
    }

    public function down(): void
    {
        Schema::table('savings_transactions', function (Blueprint $table) {
            $table->dropForeign(['reversal_of_id']);
            $table->dropIndex(['reversal_of_id']);
            $table->dropColumn(['reversal_of_id', 'entry_type']);
        });
    }
};
