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
        Schema::table('shm_loan_applications', function (Blueprint $table) {
            $table->string('marital_separation_type', 30)->nullable()->after('marital_status');
            $table->string('death_certificate_path', 500)->nullable()->after('divorce_certificate_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shm_loan_applications', function (Blueprint $table) {
            $table->dropColumn([
                'marital_separation_type',
                'death_certificate_path',
            ]);
        });
    }
};
