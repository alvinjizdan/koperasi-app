<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_disbursements', function (Blueprint $table) {
            $table->id();
            $table->string('disbursement_number', 50)->unique();
            $table->foreignId('loan_id')->constrained('loans')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('disbursement_date');
            $table->string('method', 50)->default('CASH');
            $table->foreignId('processed_by')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('loan_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_disbursements');
    }
};
