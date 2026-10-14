<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 85 — SHM Disbursement Cost Persistence.
 *
 * 1. `shm_loan_applications.notary_fee` — biaya notaris custom yang diinput
 *    pada pengajuan (opsional / non-negatif).
 * 2. `shm_loans` financial snapshot saat pencairan:
 *    - `admin_fee` (2% dari principal)
 *    - `provision_fee` (3% dari principal)
 *    - `insurance_fee` (1% dari principal)
 *    - `notary_fee` (nominal dari input pengajuan)
 *    - `principal_savings` (Rp100.000 fixed)
 *    - `mandatory_savings` (Rp25.000 × tenor_months)
 *    - `total_deduction` (total seluruh biaya potongan)
 *    - `net_disbursement` (principal - total_deduction)
 *
 * Semua kolom bertipe `decimal(18, 2)` nullable agar aman terhadap baris historis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shm_loan_applications', function (Blueprint $table) {
            $table->decimal('notary_fee', 18, 2)->nullable()->after('administration_fee');
        });

        Schema::table('shm_loans', function (Blueprint $table) {
            $table->decimal('admin_fee', 18, 2)->nullable()->after('principal');
            $table->decimal('provision_fee', 18, 2)->nullable()->after('admin_fee');
            $table->decimal('insurance_fee', 18, 2)->nullable()->after('provision_fee');
            $table->decimal('notary_fee', 18, 2)->nullable()->after('insurance_fee');
            $table->decimal('principal_savings', 18, 2)->nullable()->after('notary_fee');
            $table->decimal('mandatory_savings', 18, 2)->nullable()->after('principal_savings');
            $table->decimal('total_deduction', 18, 2)->nullable()->after('mandatory_savings');
            $table->decimal('net_disbursement', 18, 2)->nullable()->after('total_deduction');
        });
    }

    public function down(): void
    {
        Schema::table('shm_loan_applications', function (Blueprint $table) {
            $table->dropColumn('notary_fee');
        });

        Schema::table('shm_loans', function (Blueprint $table) {
            $table->dropColumn([
                'admin_fee',
                'provision_fee',
                'insurance_fee',
                'notary_fee',
                'principal_savings',
                'mandatory_savings',
                'total_deduction',
                'net_disbursement',
            ]);
        });
    }
};
