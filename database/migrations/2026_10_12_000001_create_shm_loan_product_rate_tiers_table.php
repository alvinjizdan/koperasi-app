<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 82 — Product Rate Tier (rate per exact tenor range) Pinjaman SHM.
 *
 * Rate SHM TIDAK lagi universal 2%: rate = f(product, tenor) dari matriks
 * resmi. Satu produk dapat memiliki beberapa tier rate, sehingga rate tidak
 * dapat disimpan sebagai satu kolom global pada `shm_loan_products`.
 *
 * Kontrak tier:
 * - `min_tenor_months` <= `max_tenor_months` (divalidasi service/model).
 * - Lookup EXACT: tenor valid HANYA bila min <= tenor <= max pada satu tier.
 * - Gap antar range SAH dan tetap INVALID (tidak ada interpolasi/fallback/
 *   nearest/default rate).
 * - `rate_period` = 'monthly' dan `calculation_method` = 'flat' untuk seluruh
 *   tier (baseline T79 yang tetap berlaku pasca T82).
 * - `rate_percent` presisi decimal(8, 4) — cukup untuk 1.10 … 1.70 dan
 *   turunannya tanpa pembulatan diam-diam.
 *
 * Tidak ada relasi ke domain koperasi; domain finansial SHM tetap terpisah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shm_loan_product_rate_tiers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shm_loan_product_id')
                ->constrained('shm_loan_products')
                ->restrictOnDelete();

            $table->unsignedInteger('min_tenor_months');
            $table->unsignedInteger('max_tenor_months');

            $table->decimal('rate_percent', 8, 4);

            // Baseline T79 (tetap berlaku pasca T82): monthly + flat.
            $table->string('rate_period', 10)->default('monthly');
            $table->string('calculation_method', 20)->default('flat');

            $table->timestamps();

            $table->index('shm_loan_product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shm_loan_product_rate_tiers');
    }
};
