<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 67 — Pengajuan Pinjaman SHM.
 *
 * DOMAIN DECISION (CASE B): aggregate TERPISAH dari `shm_loans`. `shm_loans`
 * merepresentasikan pinjaman yang sudah berjalan (active|closed,
 * disbursed_at), sementara pengajuan memiliki lifecycle sendiri dan data yang
 * tidak ada di `shm_loans` (penghasilan/pengeluaran, jaminan, analisa, catatan,
 * persyaratan). Konversi application → loan dilakukan ticket pencairan
 * berikutnya.
 *
 * Batasan yang disengaja:
 * - `application_number` UNIQUE dan dibuat server-side;
 * - `status` default 'pending'. Keputusan approval TIDAK disimpan di sini
 *   (lihat Ticket 68): hanya di baris ShmApproval sehingga tidak ada sumber
 *   status ganda. Nilai 'ditangguhkan' tidak dibuat (PENDING BUSINESS
 *   CONFIRMATION);
 * - rate TIDAK disimpan sebagai kolom — selalu diresolusi dari matriks
 *   produk (f(produk, tenor) — T82) sehingga tidak ada sumber nilai kedua;
 * - total penghasilan/pengeluaran/sisa adalah DERIVED dari komponen di level
 *   aplikasi, bukan kolom, sehingga tidak ada nilai ganda;
 * - komponen income/expense default 0 agar angka bisa dihitung aman;
 * - kolom finansial lain (biaya provisi, administrasi, nilai analisa)
 *   NULLABLE: angka hanya terisi bila memang diinput;
 * - `shm_loan_product_id` SET NULL karena pengajuan boleh dibuat tanpa produk
 *   dan produk boleh dinonaktifkan tanpa menghapus riwayat pengajuan;
 * - seluruh nasabah memakai `shm_customers`, TIDAK PERNAH `members`.
 *
 * Kolom `marketing_id` dan `shm_application_id` (tautan pemilik & approval,
 * Ticket 68) DITAMBAHKAN oleh migrasi 2026_10_09_000010, bukan migrasi ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shm_loan_applications', function (Blueprint $table) {
            $table->id();

            // Nomor pengajuan dibuat server-side, tidak pernah dari input klien.
            $table->string('application_number', 50)->unique();

            $table->foreignId('shm_customer_id')
                ->constrained('shm_customers')
                ->restrictOnDelete();

            // Nullable: pengajuan boleh dibuat sebelum produk dipilih.
            $table->foreignId('shm_loan_product_id')
                ->nullable()
                ->constrained('shm_loan_products')
                ->nullOnDelete();

            $table->date('application_date');
            $table->decimal('principal', 18, 2);
            $table->unsignedInteger('tenor_months');
            $table->string('purpose', 255)->nullable();

            $table->unsignedSmallInteger('loan_sequence')->nullable();
            $table->string('disbursement_method', 30)->nullable();

            // --- Section: penghasilan bulanan (komponen, total DERIVED) ---
            $table->decimal('income_fixed', 18, 2)->default(0);
            $table->decimal('income_spouse', 18, 2)->default(0);
            $table->decimal('income_business', 18, 2)->default(0);

            // --- Section: pengeluaran bulanan (komponen, total DERIVED) ---
            $table->decimal('expense_deduction', 18, 2)->default(0);
            $table->decimal('expense_household', 18, 2)->default(0);
            $table->decimal('expense_education', 18, 2)->default(0);
            $table->decimal('expense_rent', 18, 2)->default(0);
            $table->decimal('expense_utilities', 18, 2)->default(0);
            $table->decimal('expense_transport', 18, 2)->default(0);
            $table->decimal('expense_other', 18, 2)->default(0);

            // --- Section: fasilitas lain ---
            $table->decimal('koperasi_loan_balance', 18, 2)->nullable();
            $table->string('other_facility', 255)->nullable();
            $table->string('institution_name', 255)->nullable();

            // --- Section: catatan kasir ---
            $table->date('kasir_note_date')->nullable();
            $table->string('kasir_note_name', 150)->nullable();
            $table->text('kasir_note_text')->nullable();

            // --- Section: analisa kredit ---
            $table->string('analyst_code', 100)->nullable();
            $table->string('analyst_office', 150)->nullable();
            $table->string('officer_code', 100)->nullable();
            $table->string('officer_office', 150)->nullable();
            $table->date('analysis_date')->nullable();
            $table->decimal('analysis_value', 18, 2)->nullable();
            $table->text('analysis_notes')->nullable();

            $table->string('collector_name', 150)->nullable();

            // --- Section: keputusan (angka saja; status keputusan di ShmApproval)
            $table->decimal('approved_amount', 18, 2)->nullable();
            $table->decimal('provision_fee', 18, 2)->nullable();
            $table->decimal('administration_fee', 18, 2)->nullable();
            $table->string('calculation_method', 20)->nullable();
            $table->text('approval_notes')->nullable();

            // Status pengajuan. Nilai sah dikunci di App\Enums vocabulary
            // existing (pending/approved/rejected) — bukan status keputusan.
            $table->string('status', 20)->default('pending');

            $table->timestamps();

            $table->index(['shm_customer_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shm_loan_applications');
    }
};
