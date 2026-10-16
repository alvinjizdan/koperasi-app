<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * T102-FIX — Sequence nomor anggota `NNNNN-ANG` (khusus PostgreSQL).
     *
     * Terpisah penuh dari `shm_customers_code_seq` (T102): kedua sequence
     * start di 1 sehingga anggota `00001-ANG` dan nasabah SHM `00001-ANG`
     * BOLEH sama — domain berbeda, tabel berbeda, unique index per tabel.
     *
     * Kode legacy (mis. `ANG-0001`) tidak dihitung sequence dan tidak diubah.
     * Bila sudah ada kode format baru (mis. entri manual `00007-ANG` sebelum
     * sequence ada), sequence dilanjutkan dari nilai aman berikutnya agar
     * tidak menghasilkan duplikat.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE SEQUENCE IF NOT EXISTS members_code_seq START 1');

        $highestExisting = DB::selectOne(
            "SELECT COALESCE(MAX(substring(member_number from '^([0-9]{5})-ANG$')::int), 0) AS maximum
             FROM members
             WHERE member_number ~ '^[0-9]{5}-ANG$'"
        );

        if ($highestExisting !== null && (int) $highestExisting->maximum > 0) {
            DB::statement('SELECT setval(\'members_code_seq\', '.(int) $highestExisting->maximum.', true)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP SEQUENCE IF EXISTS members_code_seq');
    }
};
