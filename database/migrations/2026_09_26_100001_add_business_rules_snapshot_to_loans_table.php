<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 15 — snapshot aturan bisnis pinjaman.
 *
 * Pinjaman menyimpan metode & suku bunga saat pencairan sehingga perubahan
 * konfigurasi produk tidak mengubah pinjaman yang sudah berjalan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->string('interest_method', 20)->default('none')->after('tenor_months');
            $table->decimal('interest_rate_annual', 6, 4)->default(0)->after('interest_method');
        });

        Schema::table('installment_schedules', function (Blueprint $table) {
            $table->decimal('interest_paid', 15, 2)->default(0)->after('interest_amount');
            $table->decimal('penalty_accrued', 15, 2)->default(0)->after('interest_paid');
            $table->decimal('penalty_paid', 15, 2)->default(0)->after('penalty_accrued');
            $table->date('penalty_accrued_through')->nullable()->after('penalty_paid');
        });
    }

    public function down(): void
    {
        Schema::table('installment_schedules', function (Blueprint $table) {
            $table->dropColumn(['interest_paid', 'penalty_accrued', 'penalty_paid', 'penalty_accrued_through']);
        });

        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn(['interest_method', 'interest_rate_annual']);
        });
    }
};
