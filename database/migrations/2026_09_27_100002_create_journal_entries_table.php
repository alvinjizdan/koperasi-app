<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 18 — Journal Entries (jurnal umum) untuk fondasi akuntansi.
 *
 * - Status: DRAFT → POSTED; REVERSED ditandai pada jurnal yang dibatalkan
 *   lewat jurnal pembalik (jurnal asal tetap immutable).
 * - Referensi sumber (source_type/source_id) menautkan jurnal ke transaksi
 *   finansial modul lain; idempotensi dijaga partial unique index
 *   (source, posting_type) yang hanya berlaku untuk baris non-null.
 * - Nomor jurnal unik untuk penelusuran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('journal_number', 50)->unique();
            $table->date('transaction_date');
            $table->string('description', 255)->nullable();
            $table->string('status', 20)->default('DRAFT'); // DRAFT | POSTED | REVERSED
            $table->string('source_type', 100)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('posting_type', 50)->default('manual'); // jenis posting utk idempotensi
            $table->foreignId('reversed_by_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'transaction_date']);
            $table->index(['source_type', 'source_id']);
            $table->index('reversed_by_entry_id');

            // Idempotensi sumber: satu transaksi sumber hanya boleh menghasilkan
            // satu jurnal per jenis posting (partial unique index ala pola kasir).
            $table->unique(['source_type', 'source_id', 'posting_type'], 'journal_entries_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
