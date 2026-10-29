<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        // 1. Drop the old composite unique index that did not handle NULLs safely
        Schema::table('shm_accounting_mappings', function (Blueprint $table) {
            $table->dropUnique('shm_mapping_event_prod_unique');
        });

        // 2. Add proper partial unique indexes
        if ($driver === 'pgsql') {
            // PostgreSQL: partial unique indexes handle NULL safely
            DB::statement('CREATE UNIQUE INDEX shm_mapping_product_specific_unique ON shm_accounting_mappings (accounting_context, business_event, shm_loan_product_id) WHERE shm_loan_product_id IS NOT NULL');
            DB::statement('CREATE UNIQUE INDEX shm_mapping_generic_unique ON shm_accounting_mappings (accounting_context, business_event) WHERE shm_loan_product_id IS NULL');
        } elseif ($driver === 'sqlite') {
            // SQLite also supports partial unique indexes with WHERE clause
            DB::statement('CREATE UNIQUE INDEX shm_mapping_product_specific_unique ON shm_accounting_mappings (accounting_context, business_event, shm_loan_product_id) WHERE shm_loan_product_id IS NOT NULL');
            DB::statement('CREATE UNIQUE INDEX shm_mapping_generic_unique ON shm_accounting_mappings (accounting_context, business_event) WHERE shm_loan_product_id IS NULL');
        } else {
            // Fallback for other DBMS
            Schema::table('shm_accounting_mappings', function (Blueprint $table) {
                $table->unique(['accounting_context', 'business_event', 'shm_loan_product_id'], 'shm_mapping_product_specific_unique');
            });
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS shm_mapping_product_specific_unique');
            DB::statement('DROP INDEX IF EXISTS shm_mapping_generic_unique');
        } else {
            Schema::table('shm_accounting_mappings', function (Blueprint $table) {
                $table->dropUnique('shm_mapping_product_specific_unique');
            });
        }

        // Restore original index
        Schema::table('shm_accounting_mappings', function (Blueprint $table) {
            $table->unique(['accounting_context', 'business_event', 'shm_loan_product_id'], 'shm_mapping_event_prod_unique');
        });
    }
};
