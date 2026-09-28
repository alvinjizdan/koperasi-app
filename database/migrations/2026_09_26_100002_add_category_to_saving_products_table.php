<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 15 — kategori produk simpanan (pokok/wajib/sukarela).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saving_products', function (Blueprint $table) {
            $table->string('category', 20)->default('voluntary')->after('code');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::table('saving_products', function (Blueprint $table) {
            $table->dropIndex(['category']);
            $table->dropColumn('category');
        });
    }
};
