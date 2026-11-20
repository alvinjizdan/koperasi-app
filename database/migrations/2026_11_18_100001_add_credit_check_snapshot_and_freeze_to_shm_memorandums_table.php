<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Ticket CC-04: Menambahkan field snapshot Credit Checking dan freeze anchor pada shm_memorandums.
     */
    public function up(): void
    {
        Schema::table('shm_memorandums', function (Blueprint $table) {
            // Freeze anchor: dicatat satu kali saat first submit, tidak pernah di-reset ke null
            $table->timestampTz('first_submitted_at')->nullable()->after('submitted_at');

            // Foreign key opsional ke record credit checking yang relevan
            $table->foreignId('shm_credit_check_id')
                ->nullable()
                ->after('first_submitted_at')
                ->constrained('shm_credit_checks')
                ->nullOnDelete();

            // Snapshot JSONB permanen yang menyimpan kondisi credit checking dan/atau dispensasi
            $table->jsonb('credit_check_snapshot')->nullable()->after('shm_credit_check_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shm_memorandums', function (Blueprint $table) {
            $table->dropForeign(['shm_credit_check_id']);
            $table->dropColumn([
                'first_submitted_at',
                'shm_credit_check_id',
                'credit_check_snapshot',
            ]);
        });
    }
};
