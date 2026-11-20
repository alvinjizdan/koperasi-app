<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * TICKET 42C — Phase 3: Database-Level Accounting Idempotency
     * Replace non-unique index with partial unique indexes on PostgreSQL / SQLite:
     * 1. A single refund can only have ONE POSTED accounting recognition link across all channels.
     * 2. A single journal entry can only have ONE POSTED refund accounting recognition link.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("
                CREATE UNIQUE INDEX shm_refund_accounting_links_single_posted_refund_idx
                ON shm_refund_accounting_links (shm_settlement_refund_id)
                WHERE status = 'POSTED';
            ");

            DB::statement("
                CREATE UNIQUE INDEX shm_refund_accounting_links_single_posted_journal_idx
                ON shm_refund_accounting_links (journal_entry_id)
                WHERE status = 'POSTED';
            ");
        } elseif ($driver === 'sqlite') {
            DB::statement("
                CREATE UNIQUE INDEX IF NOT EXISTS shm_refund_accounting_links_single_posted_refund_idx
                ON shm_refund_accounting_links (shm_settlement_refund_id)
                WHERE status = 'POSTED';
            ");

            DB::statement("
                CREATE UNIQUE INDEX IF NOT EXISTS shm_refund_accounting_links_single_posted_journal_idx
                ON shm_refund_accounting_links (journal_entry_id)
                WHERE status = 'POSTED';
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS shm_refund_accounting_links_single_posted_refund_idx;');
            DB::statement('DROP INDEX IF EXISTS shm_refund_accounting_links_single_posted_journal_idx;');
        }
    }
};
