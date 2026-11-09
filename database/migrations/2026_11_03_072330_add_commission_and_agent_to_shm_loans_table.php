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
        Schema::table('shm_loans', function (Blueprint $table) {
            $table->foreignId('agent_id')
                ->nullable()
                ->after('shm_loan_application_id')
                ->constrained('shm_agents')
                ->nullOnDelete();

            $table->decimal('office_provision_fee', 18, 2)
                ->nullable()
                ->after('provision_fee');

            $table->decimal('agent_commission_fee', 18, 2)
                ->nullable()
                ->after('office_provision_fee');

            $table->index('agent_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shm_loans', function (Blueprint $table) {
            $table->dropForeign(['agent_id']);
            $table->dropIndex(['agent_id']);
            $table->dropColumn([
                'agent_id',
                'office_provision_fee',
                'agent_commission_fee',
            ]);
        });
    }
};
