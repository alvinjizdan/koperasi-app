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
            $table->string('marriage_certificate_path', 500)->nullable()->after('kk_photo_path');
            $table->string('divorce_certificate_path', 500)->nullable()->after('marriage_certificate_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shm_loan_applications', function (Blueprint $table) {
            $table->dropColumn([
                'marriage_certificate_path',
                'divorce_certificate_path',
            ]);
        });
    }
};
