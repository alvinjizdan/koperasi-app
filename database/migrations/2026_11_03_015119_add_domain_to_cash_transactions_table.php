<?php

use App\Models\ShmLoan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->string('domain', 20)
                ->default('KOPERASI')
                ->after('status');

            $table->index(['domain', 'transaction_date'], 'cash_transactions_domain_date_index');
            $table->index(['domain', 'status'], 'cash_transactions_domain_status_index');
        });

        // Set existing records appropriately based on concrete evidence:
        // Transaksi yang bersumber dari ShmLoan diklasifikasikan sebagai SHM.
        DB::table('cash_transactions')
            ->where('source_type', ShmLoan::class)
            ->update(['domain' => 'SHM']);

        // Sisanya tetap KOPERASI sesuai default.
        DB::table('cash_transactions')
            ->whereNull('domain')
            ->update(['domain' => 'KOPERASI']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropIndex('cash_transactions_domain_status_index');
            $table->dropIndex('cash_transactions_domain_date_index');
            $table->dropColumn('domain');
        });
    }
};
