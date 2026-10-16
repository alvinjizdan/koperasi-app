<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * T102 — Sequence nomor Nasabah SHM (format resmi `NNNNN-ANG`).
 *
 * Nomor nasabah SHM kini urut per domain (`00001-ANG`, `00002-ANG`, ...):
 * SEQUENCE INDEPENDEN dari nomor anggota koperasi — kode anggota tidak pernah
 * dipakai sebagai kode nasabah dan sebaliknya. Pola pengambilan nilai memakai
 * sequence PostgreSQL `nextval`, mengikuti mekanisme existing
 * SavingsAccount::generateAccountNumber (aman konkuren), dengan index unique
 * pada shm_customers.customer_number sebagai safety net terakhir.
 *
 * Sequence DIMULAI DARI 1 sehingga nasabah format baru pertama memperoleh
 * `00001-ANG`. Data legacy format `NSB-...` tidak disentuh oleh migrasi ini
 * dan tidak ikut dihitung dalam sequence.
 *
 * Driver non-PostgreSQL (suite test sqlite :memory:) tidak membuat sequence:
 * pada driver tersebut generator memakai fallback pembacaan kode tertinggi
 * yang tetap dijaga unique constraint + retry.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE SEQUENCE IF NOT EXISTS shm_customers_code_seq START 1');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP SEQUENCE IF EXISTS shm_customers_code_seq');
    }
};
