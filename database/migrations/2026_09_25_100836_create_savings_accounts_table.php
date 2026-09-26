<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('savings_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_number', 30)->unique();
            $table->foreignId('member_id')->constrained('members')->restrictOnDelete();
            $table->foreignId('saving_product_id')->constrained('saving_products')->restrictOnDelete();
            $table->date('opened_at');
            $table->string('status', 20)->default('active'); // active | closed
            $table->decimal('balance', 15, 2)->default(0);
            $table->timestamps();

            $table->index('member_id');
            $table->index('saving_product_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('savings_accounts');
    }
};
