<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->string('loan_number', 50)->unique();
            $table->foreignId('member_id')->constrained('members')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->integer('tenor_months');
            $table->date('submission_date');
            $table->string('status', 30)->default('PENDING'); // PENDING, APPROVED, REJECTED, DISBURSED, ACTIVE, PAID_OFF, CANCELLED
            $table->date('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->date('disbursed_at')->nullable();
            $table->foreignId('disbursed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('first_due_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('member_id');
            $table->index('status');
            $table->index('submission_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
