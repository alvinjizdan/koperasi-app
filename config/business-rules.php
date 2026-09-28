<?php

/*
|--------------------------------------------------------------------------
| Aturan Bisnis Umum — DEFAULT PROVISIONAL MVP
|--------------------------------------------------------------------------
|
| Ticket 15. Seluruh nilai di file ini adalah DEFAULT PROVISIONAL untuk
| standar MVP, BUKAN keputusan resmi koperasi. Setiap parameter wajib
| dikonfirmasi melalui docs/BUSINESS-DATA-REQUEST.md (D-01..D-12) sebelum
| dinyatakan final.
|
| Prinsip:
| - Pinjaman menyimpan snapshot metode/suku bunga saat pencairan; perubahan
|   nilai di sini TIDAK mengubah pinjaman yang sudah berjalan.
| - Bunga nol hanya untuk produk yang eksplisit dikonfigurasi tanpa bunga.
| - Jika konfigurasi bunga belum lengkap, pencairan ditolak dengan pesan jelas.
| - Jika konfigurasi denda belum tersedia, denda TIDAK diterapkan otomatis;
|   denda nol bukan keputusan bahwa koperasi tidak mengenakan denda.
|
*/

return [

    'loan' => [

        // Metode bunga yang didukung layanan perhitungan terpusat.
        // none = produk pinjaman tanpa bunga (eksplisit).
        'interest_methods' => [
            'none' => 'Tanpa bunga (pinjaman tanpa bunga)',
            'flat' => 'Bunga flat (dihitung dari pokok awal)',
            'effective' => 'Bunga efektif (saldo pokok menurun)',
            'annuity' => 'Anuitas (angsuran tetap)',
        ],

        // Default pembuatan pinjaman baru (provisional).
        'default_interest_method' => 'flat',
        'default_annual_rate' => 0.0, // persen per tahun; 0 = tanpa bunga sampai dikonfirmasi

        // Basis periode perhitungan bunga.
        'rate_basis' => 'annual',          // annual | monthly
        'months_per_year' => 12,

        // Toleransi pelunasan (Rp): sisa kewajiban <= toleransi dianggap lunas.
        'settlement_tolerance' => 0.01,

        // Pembulatan nominal perhitungan (desimal).
        'rounding_decimals' => 2,

        // Validasi minimum tenor.
        'min_tenor_months' => 1,
        'max_tenor_months' => 120,
    ],

    'penalty' => [

        // Metode denda: none (belum dikonfirmasi), fixed, percent, per_day.
        // "none" BUKAN keputusan bahwa koperasi tidak mengenakan denda —
        // hanya berarti konfigurasi belum tersedia sehingga denda tidak
        // diterapkan otomatis (Ticket 15 aturan D.6/D.7).
        'method' => 'none',

        // Parameter per metode (dipakai sesuai metode terpilih).
        'fixed_amount' => 0.0,          // Rp per periode terlambat
        'percent_per_period' => 0.0,    // persen dari angsuran jatuh tempo per periode
        'amount_per_day' => 0.0,        // Rp per hari keterlambatan

        // Grace period sebelum denda dihitung (hari).
        'grace_days' => 0,

        // Batas maksimum denda per angsuran (Rp); null = tanpa batas.
        'max_amount_per_installment' => null,

        // Basis penentu "periode terlambat" untuk metode fixed/percent.
        'period_unit' => 'month',       // month | day
    ],

    'allocation' => [

        // Urutan alokasi pembayaran ke komponen kewajiban (provisional).
        // Pilihan komponen: penalty, interest, principal.
        'order' => ['penalty', 'interest', 'principal'],
    ],

    'savings' => [

        // Kategori produk simpanan yang didukung (provisional, sesuai umum
        // koperasi; daftar final menunggu konfirmasi D-05).
        'product_categories' => [
            'voluntary' => 'Simpanan sukarela',
            'mandatory' => 'Simpanan wajib',
            'principal' => 'Simpanan pokok',
        ],

        // Default kategori produk baru.
        'default_product_category' => 'voluntary',

        // Nominal minimum per transaksi (provisional, konfirmasi D-02/B-10).
        'min_transaction_amount' => 0.01,

        // Kelayakan transaksi berdasarkan status anggota.
        'allow_transactions_for_inactive_member' => false,

        // Jumlah rekening maksimum per anggota per kategori produk
        // (null = tanpa batas; provisional).
        'max_accounts_per_category' => null,
    ],

    'cashier' => [

        // Selisih kas di akhir sesi dicatat apa adanya; ambang pengabaian.
        'difference_tolerance' => 0.01,
    ],

];
