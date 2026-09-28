<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 21 — kolom pelacakan reversal pembayaran angsuran.
 *
 * Mengikuti pola reversal simpanan (Ticket 16/20): pembayaran asal TIDAK diubah
 * secara finansial; status menjadi REVERSED dan dibuat pembayaran penyesuaian
 * (entry_type=reversal) yang mereferensikan pembayaran asal via reversal_of_id.
 * Riwayat finansial tetap utuh dan dapat ditelusuri.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installment_payments', function (Blueprint $table) {
            $table->foreignId('reversal_of_id')
                ->nullable()
                ->constrained('installment_payments')
                ->nullOnDelete()
                ->after('idempotency_key');
            $table->string('entry_type', 20)->default('original')->after('reversal_of_id'); // original | reversal
            $table->index('reversal_of_id');
        });
    }

    public function down(): void
    {
        Schema::table('installment_payments', function (Blueprint $table) {
            $table->dropForeign(['reversal_of_id']);
            $table->dropIndex(['reversal_of_id']);
            $table->dropColumn(['reversal_of_id', 'entry_type']);
        });
    }
};
