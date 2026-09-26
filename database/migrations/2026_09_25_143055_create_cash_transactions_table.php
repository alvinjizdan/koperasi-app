<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_number', 50)->unique();
            $table->foreignId('cashier_session_id')->nullable()->constrained('cashier_sessions')->restrictOnDelete();
            $table->string('type', 20); // INCOME | EXPENSE | DEPOSIT
            $table->string('category', 50)->nullable();
            $table->decimal('amount', 15, 2);
            $table->date('transaction_date');
            $table->timestamp('transacted_at');
            $table->text('description')->nullable();
            $table->string('document_reference', 100)->nullable();
            $table->foreignId('processed_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('POSTED'); // POSTED | REVERSED

            // Referensi transaksi sumber (Simpanan, Pinjaman, Angsuran) untuk idempotensi lintas modul.
            $table->string('source_type', 150)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            // Jejak audit koreksi/pembatalan. Transaksi tidak pernah dihapus fisik.
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('reversal_reason')->nullable();

            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->timestamps();

            $table->index('cashier_session_id');
            $table->index('type');
            $table->index('category');
            $table->index('transaction_date');
            $table->index('processed_by');
            $table->index('status');
        });

        // Satu transaksi bisnis sumber hanya boleh menghasilkan satu dampak kas.
        DB::statement(
            'CREATE UNIQUE INDEX cash_transactions_source_unique ON cash_transactions (source_type, source_id) WHERE source_type IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_transactions');
    }
};
