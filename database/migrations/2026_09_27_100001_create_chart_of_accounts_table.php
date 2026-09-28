<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 18 — Chart of Accounts (fondasi akuntansi berpasangan).
 *
 * Struktur mengikuti dokumen FINANCIAL-DATA-SOURCES.md §7.1: daftar perkiraan
 * dengan kategori standar akuntansi dan saldo normal. COA resmi koperasi
 * belum ditetapkan — seeder hanya mengisi TEMPLATE awal yang dapat diedit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 150);
            $table->string('category', 20); // asset | liability | equity | revenue | expense
            $table->string('normal_balance', 6); // debit | credit
            $table->foreignId('parent_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->boolean('is_template')->default(false); // data awal, bukan COA resmi
            $table->timestamps();

            $table->index(['category', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chart_of_accounts');
    }
};
