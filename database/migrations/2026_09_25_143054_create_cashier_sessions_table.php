<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_number', 50)->unique();
            $table->date('operational_date');
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->text('opening_notes')->nullable();
            $table->string('status', 20)->default('OPEN'); // OPEN | CLOSED
            $table->timestamp('opened_at');

            // Kolom agregat diisi saat sesi ditutup sebagai snapshot audit.
            $table->decimal('total_income', 15, 2)->default(0);
            $table->decimal('total_expense', 15, 2)->default(0);
            $table->decimal('total_deposit', 15, 2)->default(0);
            $table->decimal('system_closing_balance', 15, 2)->nullable();
            $table->decimal('physical_closing_balance', 15, 2)->nullable();
            $table->decimal('difference', 15, 2)->nullable();

            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('closing_notes')->nullable();
            $table->timestamps();

            $table->index('operational_date');
            $table->index('status');
            $table->index('opened_by');
        });

        // Satu operator hanya boleh memiliki satu sesi kasir yang masih OPEN.
        // Partial unique index didukung oleh PostgreSQL (produksi) dan SQLite (pengujian).
        DB::statement(
            'CREATE UNIQUE INDEX cashier_sessions_operator_open_unique ON cashier_sessions (opened_by) WHERE status = \'OPEN\''
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_sessions');
    }
};
