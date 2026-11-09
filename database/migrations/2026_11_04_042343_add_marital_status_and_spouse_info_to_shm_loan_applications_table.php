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
            $table->string('marital_status', 20)->nullable()->after('batas_hutang');
            $table->string('spouse_name', 150)->nullable()->after('marital_status');
            $table->string('spouse_nik', 50)->nullable()->after('spouse_name');
            $table->string('spouse_phone', 30)->nullable()->after('spouse_nik');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shm_loan_applications', function (Blueprint $table) {
            $table->dropColumn([
                'marital_status',
                'spouse_name',
                'spouse_nik',
                'spouse_phone',
            ]);
        });
    }
};
