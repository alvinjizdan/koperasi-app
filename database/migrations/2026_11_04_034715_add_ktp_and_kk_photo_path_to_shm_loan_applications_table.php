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
            $table->string('ktp_photo_path')->nullable()->after('batas_hutang');
            $table->string('kk_photo_path')->nullable()->after('ktp_photo_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shm_loan_applications', function (Blueprint $table) {
            $table->dropColumn(['ktp_photo_path', 'kk_photo_path']);
        });
    }
};
