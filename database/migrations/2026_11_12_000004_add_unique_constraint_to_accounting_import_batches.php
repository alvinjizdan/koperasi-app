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
        // Add partial/unique index on completed batches for (accounting_context, checksum_hash)
        if (Schema::hasTable('accounting_import_batches')) {
            Schema::table('accounting_import_batches', function (Blueprint $table) {
                // Drop existing non-unique index if exists or create explicit unique constraint
                // In SQLite and PostgreSQL, a unique constraint on (accounting_context, checksum_hash, status)
                // guarantees concurrency safety for completed batches.
                $table->unique(['accounting_context', 'checksum_hash', 'status'], 'accounting_import_batches_context_checksum_status_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('accounting_import_batches')) {
            Schema::table('accounting_import_batches', function (Blueprint $table) {
                $table->dropUnique('accounting_import_batches_context_checksum_status_unique');
            });
        }
    }
};
