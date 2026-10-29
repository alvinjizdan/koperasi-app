<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shm_accounting_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('accounting_context', 32)->default('shm');
            $table->string('business_event', 64);
            $table->foreignId('shm_loan_product_id')->nullable()->constrained('shm_loan_products')->restrictOnDelete();

            // Debit & Credit account roles / accounts
            $table->string('debit_account_role', 64)->nullable();
            $table->foreignId('debit_account_id')->nullable()->constrained('chart_of_accounts')->restrictOnDelete();

            $table->string('credit_account_role', 64)->nullable();
            $table->foreignId('credit_account_id')->nullable()->constrained('chart_of_accounts')->restrictOnDelete();

            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Constraint: Uniqueness per business event per product (or null product) per context
            $table->unique(['accounting_context', 'business_event', 'shm_loan_product_id'], 'shm_mapping_event_prod_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shm_accounting_mappings');
    }
};
