<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Ticket CC-04: Tabel persisten dispensasi & exception Credit Checking.
     */
    public function up(): void
    {
        Schema::create('shm_credit_check_exceptions', function (Blueprint $table) {
            $table->id();

            $table->string('exception_number', 32)->unique();

            // Scope wajib terikat pada satu aplikasi SHM
            $table->foreignId('shm_application_id')
                ->constrained('shm_applications')
                ->restrictOnDelete();

            // Tipe: completion_bypass atau fail_override
            $table->string('exception_type', 32);

            // Status: authorized, consumed, revoked, expired
            $table->string('status', 20)->default('authorized');

            // Otoritas eksekutif yang menyetujui (Direktur Utama)
            $table->foreignId('authorized_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('authorized_by_name', 255);
            $table->timestampTz('authorized_at');

            // Kondisi yang memblokir
            $table->string('blocking_condition', 255);

            // Justifikasi internal rahasia (INTERNAL_RESTRICTED)
            $table->text('internal_justification');

            // Ringkasan keterbukaan resmi untuk dicetak/ditampilkan (OPERATIONAL_CONFIDENTIAL)
            $table->string('official_disclosure_summary', 500);

            // Jejak konsumsi oleh memorandum
            $table->foreignId('consumed_memorandum_id')
                ->nullable()
                ->constrained('shm_memorandums')
                ->restrictOnDelete();

            $table->timestampTz('consumed_at')->nullable();

            // Jejak pembatalan / revocation jika ada
            $table->foreignId('revoked_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestampTz('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();

            $table->bigInteger('audit_event_id')->nullable();

            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            // Indeks pendukung lookup operasional
            $table->index(['shm_application_id', 'status'], 'shm_cc_exp_app_status_idx');
            $table->index('exception_type', 'shm_cc_exp_type_idx');
        });

        // Hardening Integritas State di Level Database (PostgreSQL & SQLite kompatibel)
        // 1. Validasi ENUM values untuk exception_type
        DB::statement("
            ALTER TABLE shm_credit_check_exceptions 
            ADD CONSTRAINT chk_shm_cc_exp_type 
            CHECK (exception_type IN ('completion_bypass', 'fail_override'))
        ");

        // 2. Validasi ENUM values untuk status
        DB::statement("
            ALTER TABLE shm_credit_check_exceptions 
            ADD CONSTRAINT chk_shm_cc_exp_status 
            CHECK (status IN ('authorized', 'consumed', 'revoked', 'expired'))
        ");

        // 3. Konsistensi state: consumed mewajibkan consumed_at dan consumed_memorandum_id terisi
        DB::statement("
            ALTER TABLE shm_credit_check_exceptions 
            ADD CONSTRAINT chk_shm_cc_exp_consumed_state 
            CHECK (
                (status = 'consumed' AND consumed_at IS NOT NULL AND consumed_memorandum_id IS NOT NULL) OR 
                (status != 'consumed' AND consumed_at IS NULL AND consumed_memorandum_id IS NULL)
            )
        ");

        // 4. Konsistensi state: revoked mewajibkan revoked_by, revoked_at, dan revocation_reason terisi
        DB::statement("
            ALTER TABLE shm_credit_check_exceptions 
            ADD CONSTRAINT chk_shm_cc_exp_revoked_state 
            CHECK (
                (status = 'revoked' AND revoked_by IS NOT NULL AND revoked_at IS NOT NULL AND revocation_reason IS NOT NULL) OR 
                (status != 'revoked' AND revoked_by IS NULL AND revoked_at IS NULL AND revocation_reason IS NULL)
            )
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shm_credit_check_exceptions');
    }
};
