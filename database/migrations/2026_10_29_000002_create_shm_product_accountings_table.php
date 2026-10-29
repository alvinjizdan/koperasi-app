<?php

use App\Support\AccountingContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('shm_product_accountings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shm_loan_product_id')->constrained('shm_loan_products')->cascadeOnDelete();
            $table->string('accounting_context', 20)->default(AccountingContext::SHM);

            // Mapping CoA Accounts
            $table->foreignId('cash_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignId('receivable_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignId('interest_revenue_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignId('penalty_revenue_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignId('settlement_discount_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Uniqueness: exactly one active configuration per product per context
            $table->unique(['shm_loan_product_id', 'accounting_context'], 'shm_product_accountings_product_context_unique');
            $table->index(['accounting_context', 'is_active'], 'shm_product_accountings_context_active_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shm_product_accountings');
    }
};
