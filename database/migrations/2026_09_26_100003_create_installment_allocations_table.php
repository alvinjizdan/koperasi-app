<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 15 — rincian alokasi pembayaran angsuran per komponen kewajiban.
 *
 * Setiap pembayaran menghasilkan baris alokasi per (schedule, komponen)
 * agar penelusuran dan rekonsiliasi tidak bergantung pada rekonstruksi
 * perhitungan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installment_payment_id')->constrained('installment_payments')->cascadeOnDelete();
            $table->foreignId('installment_schedule_id')->constrained('installment_schedules')->restrictOnDelete();
            $table->string('component', 20); // penalty | interest | principal
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->unique(['installment_payment_id', 'installment_schedule_id', 'component'], 'allocations_payment_schedule_component_unique');
            $table->index('installment_schedule_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_allocations');
    }
};
