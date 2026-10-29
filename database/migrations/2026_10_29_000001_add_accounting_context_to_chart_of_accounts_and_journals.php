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
        // 1. chart_of_accounts
        if (! Schema::hasColumn('chart_of_accounts', 'accounting_context')) {
            Schema::table('chart_of_accounts', function (Blueprint $table) {
                $table->string('accounting_context', 20)->default(AccountingContext::KOPERASI)->after('id');
            });
        }

        // SQLite or test databases might have old unique constraint
        try {
            Schema::table('chart_of_accounts', function (Blueprint $table) {
                $table->dropUnique('chart_of_accounts_code_unique');
            });
        } catch (Throwable $e) {
        }

        try {
            Schema::table('chart_of_accounts', function (Blueprint $table) {
                $table->unique(['accounting_context', 'code'], 'chart_of_accounts_context_code_unique');
            });
        } catch (Throwable $e) {
        }

        try {
            Schema::table('chart_of_accounts', function (Blueprint $table) {
                $table->index(['accounting_context', 'is_active'], 'chart_of_accounts_context_active_index');
            });
        } catch (Throwable $e) {
        }

        // 2. journal_entries
        if (! Schema::hasColumn('journal_entries', 'accounting_context')) {
            Schema::table('journal_entries', function (Blueprint $table) {
                $table->string('accounting_context', 20)->default(AccountingContext::KOPERASI)->after('journal_number');
            });
        }

        try {
            Schema::table('journal_entries', function (Blueprint $table) {
                $table->index(['accounting_context', 'status', 'transaction_date'], 'journal_entries_context_status_date_index');
            });
        } catch (Throwable $e) {
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        try {
            Schema::table('journal_entries', function (Blueprint $table) {
                $table->dropIndex('journal_entries_context_status_date_index');
            });
        } catch (Throwable $e) {
        }

        try {
            Schema::table('chart_of_accounts', function (Blueprint $table) {
                $table->dropIndex('chart_of_accounts_context_active_index');
                $table->dropUnique('chart_of_accounts_context_code_unique');
                $table->unique('code', 'chart_of_accounts_code_unique');
            });
        } catch (Throwable $e) {
        }
    }
};
