<?php

namespace App\Http\Requests\Shm;

use App\Services\Shm\ShmLoanSimulationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ticket 67 — validasi server-side penyimpanan Pengajuan Pinjaman SHM.
 *
 * Bentuk payload = bentuk state form T66 (group per section, dotted keys).
 *
 * Yang dijaga:
 * - nasabah WAJIB berasal dari `shm_customers` (exists) — id anggota
 *   koperasi/gagal validasi;
 * - produk (opsional) valid terhadap master produk SHM yang aktif;
 * - batas numerik pinjaman/tenor mengikuti contract simulasi existing
 *   (LoanSimulationRequest) — bukan aturan baru;
 * - nilai finansial non-negatif; tidak ada batas minimum usaha/eligibility;
 * - `persetujuan.method` divalidasi terhadap supportedMethods() — sistem
 *   TIDAK PERNAH menebak metode;
 * - rate TIDAK diterima dari klien sama sekali (rate resmi dari matriks
 *   produk — T82).
 *
 * Field GAP T66 (data tambahan analisa) tetap tidak ada dalam payload maupun
 * skema (PENDING BUSINESS CONFIRMATION).
 *
 * Ticket 77 — payload boleh berupa multipart/form-data bila ada foto
 * jaminan (`jaminan.*.foto`); aturan lain tidak berubah.
 *
 * Ticket 76-FIX — field Data Pribadi kini menjadi DATA PENGAJUAN
 * (`shm_loan_applications`), bukan data master nasabah. Sembilan field di
 * Section 1 bersifat nullable & manual — tanpa formula, tanpa format resmi
 * (PENDING BUSINESS CONFIRMATION untuk NIP/telepon/tanggal lahir).
 */
class StoreShmLoanApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Otorisasi route dilakukan middleware `permission:shm.view`
        // (satu-satunya permission SHM yang dipakai modul Pinjaman);
        // tidak ada permission baru.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $methods = array_keys(ShmLoanSimulationService::supportedMethods());
        $maxAmount = 1_000_000_000_000;

        return [
            // Section 1 — Data Pribadi: referensi ke nasabah SHM.
            'pribadi.customer_id' => ['required', 'integer', Rule::exists('shm_customers', 'id')],

            // Ticket 76-FIX — Data Pribadi adalah snapshot per pengajuan
            // (`shm_loan_applications`), bukan data master nasabah. Semua
            // nullable; tanpa formula & tanpa format resmi (PENDING BUSINESS
            // CONFIRMATION).
            'pribadi.unit_kerja' => ['nullable', 'string', 'max:150'],
            // String: NIP dapat memiliki leading zero.
            'pribadi.nip' => ['nullable', 'string', 'max:50'],
            'pribadi.telepon_rumah' => ['nullable', 'string', 'max:30'],
            'pribadi.tempat_lahir' => ['nullable', 'string', 'max:100'],
            'pribadi.tanggal_lahir' => ['nullable', 'date'],
            'pribadi.jumlah_tanggungan' => ['nullable', 'integer', 'min:0', 'max:100'],
            'pribadi.saldo_modal' => ['nullable', 'numeric', 'min:0', 'max:'.$maxAmount],
            'pribadi.saldo_non_modal' => ['nullable', 'numeric', 'min:0', 'max:'.$maxAmount],
            'pribadi.batas_hutang' => ['nullable', 'numeric', 'min:0', 'max:'.$maxAmount],

            // Section 2 — Pengajuan Pinjaman.
            'pengajuan.product_id' => ['nullable', 'integer', Rule::exists('shm_loan_products', 'id')->where('is_active', true)],
            'pengajuan.principal' => ['required', 'numeric', 'min:1', 'max:'.$maxAmount],
            'pengajuan.tenor_months' => ['required', 'integer', 'min:1', 'max:600'],
            // Ticket 82 — jatuh tempo pertama DIPILIH Marketing (date, bukan
            // datetime, bukan hasil hitung otomatis dari tanggal pencairan).
            // Wajib diisi sebelum pencairan (gate di ShmLoanDisbursementService).
            'pengajuan.first_due_date' => ['nullable', 'date'],
            'pengajuan.purpose' => ['nullable', 'string', 'max:255'],
            'pengajuan.loan_sequence' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'pengajuan.disbursement_method' => ['nullable', Rule::in(['Tunai', 'Debet Tabungan', 'Bank'])],

            // Section 3 — Penghasilan & pengeluaran (non-negatif; tanpa
            // aturan eligibility/DSCR).
            'income.tetap' => ['nullable', 'numeric', 'min:0', 'max:'.$maxAmount],
            'income.pasangan' => ['nullable', 'numeric', 'min:0', 'max:'.$maxAmount],
            'income.usaha' => ['nullable', 'numeric', 'min:0', 'max:'.$maxAmount],
            'expense.potongan' => ['nullable', 'numeric', 'min:0', 'max:'.$maxAmount],
            'expense.rumahTangga' => ['nullable', 'numeric', 'min:0', 'max:'.$maxAmount],
            'expense.pendidikan' => ['nullable', 'numeric', 'min:0', 'max:'.$maxAmount],
            'expense.sewa' => ['nullable', 'numeric', 'min:0', 'max:'.$maxAmount],
            'expense.listrik' => ['nullable', 'numeric', 'min:0', 'max:'.$maxAmount],
            'expense.transportasi' => ['nullable', 'numeric', 'min:0', 'max:'.$maxAmount],
            'expense.lain' => ['nullable', 'numeric', 'min:0', 'max:'.$maxAmount],

            // Section 4 — Data Tambahan.
            'tambahan.koperasi_loan_balance' => ['nullable', 'numeric', 'min:0', 'max:'.$maxAmount],
            'tambahan.other_facility' => ['nullable', 'string', 'max:255'],
            'tambahan.institution_name' => ['nullable', 'string', 'max:150'],
            'agunan' => ['nullable', 'array', 'max:20'],
            'agunan.*.jenis' => ['required', 'string', 'max:150'],
            'agunan.*.nomor' => ['nullable', 'string', 'max:100'],
            'agunan.*.tahun' => ['nullable', 'string', 'max:4'],
            'agunan.*.nilai' => ['nullable', 'numeric', 'min:0', 'max:'.$maxAmount],

            // Section 5 — Jaminan Pinjaman.
            'jaminan' => ['nullable', 'array', 'max:20'],
            'jaminan.*.tanggal' => ['nullable', 'date'],
            'jaminan.*.jenis' => ['required', 'string', 'max:150'],
            'jaminan.*.nama' => ['required', 'string', 'max:150'],
            'jaminan.*.keterangan' => ['nullable', 'string'],
            'jaminan.*.nilai_perkiraan' => ['nullable', 'numeric', 'min:0', 'max:'.$maxAmount],
            'jaminan.*.status_pengikatan' => ['nullable', 'string', 'max:100'],
            'jaminan.*.tanggal_pengikatan' => ['nullable', 'date'],
            'jaminan.*.nilai_hak_tanggungan' => ['nullable', 'numeric', 'min:0', 'max:'.$maxAmount],
            // Ticket 77 — foto jaminan (multipart). Daftar format resmi bisnis
            // belum dikonfirmasi (PENDING BUSINESS CONFIRMATION); gunakan
            // format gambar yang aman & umum (JPEG/PNG/WebP) tanpa
            // executable/script, dan batas 2 MB sebagai batas teknis wajar
            // untuk satu berkas gambar dokumentasi jaminan.
            'jaminan.*.foto' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],

            // Section 6 — Catatan Kasir / SC.
            'catatan.date' => ['nullable', 'date'],
            'catatan.name' => ['nullable', 'string', 'max:150'],
            'catatan.notes' => ['nullable', 'string'],

            // Section 7 — Analisa Pinjaman (tanpa scoring).
            'analisa.analyst_code' => ['nullable', 'string', 'max:100'],
            'analisa.analyst_office' => ['nullable', 'string', 'max:150'],
            'analisa.officer_code' => ['nullable', 'string', 'max:100'],
            'analisa.officer_office' => ['nullable', 'string', 'max:150'],
            'analisa.date' => ['nullable', 'date'],
            'analisa.value' => ['nullable', 'numeric', 'min:0', 'max:'.$maxAmount],
            'analisa.notes' => ['nullable', 'string'],

            // Section 9 — Kolektor (teks bebas; master belum ada).
            'kolektor.name' => ['nullable', 'string', 'max:150'],

            // Section 10 — Persetujuan (tanpa formula biaya; method eksplisit).
            'persetujuan.approved_amount' => ['nullable', 'numeric', 'min:0', 'max:'.$maxAmount],
            'persetujuan.provision_fee' => ['nullable', 'numeric', 'min:0', 'max:'.$maxAmount],
            'persetujuan.administration_fee' => ['nullable', 'numeric', 'min:0', 'max:'.$maxAmount],
            // Ticket 85 — biaya notaris custom (non-negatif; tanpa preset &
            // tanpa batas maksimum — PENDING BUSINESS CONFIRMATION).
            'persetujuan.notary_fee' => ['nullable', 'numeric', 'min:0', 'max:'.$maxAmount],
            'persetujuan.method' => ['nullable', Rule::in($methods)],
            'persetujuan.notes' => ['nullable', 'string'],

            // Section 11 — Persyaratan tambahan.
            'persyaratan' => ['nullable', 'array', 'max:50'],
            'persyaratan.*.text' => ['required', 'string', 'max:255'],
            'persyaratan.*.done' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pribadi.customer_id.required' => 'Nasabah SHM wajib dipilih.',
            'pribadi.customer_id.exists' => 'Nasabah SHM tidak ditemukan.',
            'pengajuan.product_id.exists' => 'Produk pinjaman tidak ditemukan atau sudah nonaktif.',
            'pengajuan.principal.required' => 'Besar pinjaman wajib diisi.',
            'pengajuan.principal.numeric' => 'Besar pinjaman harus berupa angka.',
            'pengajuan.principal.min' => 'Besar pinjaman harus lebih dari 0.',
            'pengajuan.tenor_months.required' => 'Jangka waktu wajib diisi.',
            'pengajuan.tenor_months.integer' => 'Jangka waktu harus berupa bilangan bulat.',
            'pengajuan.tenor_months.min' => 'Jangka waktu minimal 1 kali angsuran.',
            'pengajuan.disbursement_method.in' => 'Metode penyetoran/pembayaran angsuran tidak valid.',
            'persetujuan.method.in' => 'Metode perhitungan tidak dikenal. Pilih salah satu metode yang didukung.',
            'jaminan.*.jenis.required' => 'Jenis jaminan wajib diisi.',
            'jaminan.*.nama.required' => 'Nama jaminan wajib diisi.',
            'jaminan.*.foto.mimes' => 'Foto jaminan hanya boleh berupa gambar JPEG, PNG, atau WebP.',
            'jaminan.*.foto.max' => 'Ukuran foto jaminan maksimal 2 MB.',
            'agunan.*.jenis.required' => 'Jenis barang agunan wajib diisi.',
            'persyaratan.*.text.required' => 'Isi persyaratan tambahan atau hapus baris kosong.',
        ];
    }
}
