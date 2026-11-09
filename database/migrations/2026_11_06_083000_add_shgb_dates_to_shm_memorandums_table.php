<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 7 — Masa Berlaku SHGB pada Memorandum SHM/SHGB.
 *
 * Menambahkan field tanggal mulai dan berakhir masa berlaku SHGB.
 * Field bersifat nullable sehingga kompatibel dengan data historis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shm_memorandums', function (Blueprint $table) {
            $table->date('shgb_start_date')->nullable()->after('notes');
            $table->date('shgb_end_date')->nullable()->after('shgb_start_date');
        });
    }

    public function down(): void
    {
        Schema::table('shm_memorandums', function (Blueprint $table) {
            $table->dropColumn(['shgb_start_date', 'shgb_end_date']);
        });
    }
};
