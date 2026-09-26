<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('savings_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_number', 50)->unique();
            $table->foreignId('savings_account_id')->constrained('savings_accounts')->restrictOnDelete();
            $table->string('type', 20); // deposit | withdrawal
            $table->decimal('amount', 15, 2);
            $table->date('transaction_date');
            $table->text('description')->nullable();
            $table->foreignId('processed_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('completed'); // completed | reversed
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->timestamp('created_at')->useCurrent();
            // Purposely no updated_at: transactions are immutable records.

            $table->index('savings_account_id');
            $table->index('type');
            $table->index('transaction_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('savings_transactions');
    }
};
