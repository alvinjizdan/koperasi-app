<?php

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
        Schema::create('shm_saving_products', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('category', 20)->index(); // principal | mandatory | voluntary
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('shm_savings_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_number', 30)->unique();
            $table->foreignId('shm_customer_id')->constrained('shm_customers')->restrictOnDelete();
            $table->foreignId('shm_saving_product_id')->constrained('shm_saving_products')->restrictOnDelete();
            $table->date('opened_at');
            $table->string('status', 20)->default('active'); // active | closed
            $table->decimal('balance', 15, 2)->default(0);
            $table->timestamps();

            $table->index('shm_customer_id');
            $table->index('shm_saving_product_id');
            $table->index('status');
        });

        Schema::create('shm_savings_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_number', 50)->unique();
            $table->foreignId('shm_savings_account_id')->constrained('shm_savings_accounts')->restrictOnDelete();
            $table->string('type', 20); // deposit | withdrawal
            $table->decimal('amount', 15, 2);
            $table->date('transaction_date');
            $table->text('description')->nullable();
            $table->foreignId('processed_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('completed'); // completed | reversed
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->foreignId('reversal_of_id')->nullable()->constrained('shm_savings_transactions')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index('shm_savings_account_id');
            $table->index('type');
            $table->index('transaction_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shm_savings_transactions');
        Schema::dropIfExists('shm_savings_accounts');
        Schema::dropIfExists('shm_saving_products');
    }
};
