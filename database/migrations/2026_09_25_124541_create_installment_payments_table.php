<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installment_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number', 50)->unique();
            $table->foreignId('loan_id')->constrained('loans')->restrictOnDelete();
            $table->foreignId('installment_schedule_id')->nullable()->constrained('installment_schedules')->restrictOnDelete();
            $table->date('payment_date');
            $table->decimal('amount', 15, 2);
            $table->foreignId('processed_by')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('COMPLETED'); // COMPLETED, REVERSED
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->timestamp('created_at')->useCurrent();

            $table->index('loan_id');
            $table->index('installment_schedule_id');
            $table->index('payment_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_payments');
    }
};
