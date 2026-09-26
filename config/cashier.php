<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Kategori Transaksi Kas
    |--------------------------------------------------------------------------
    |
    | ATURAN BISNIS BELUM DIKONFIRMASI.
    |
    | Daftar di bawah adalah nilai awal yang dapat disesuaikan oleh pengelola
    | koperasi tanpa perubahan kode. Kategori penerimaan dan pengeluaran wajib
    | dikonfirmasi sebelum digunakan pada operasional nyata. Modul ini tidak
    | membuat jurnal akuntansi atau kategori pendapatan akuntansi secara otomatis.
    |
    */

    'categories' => [

        'income' => [
            'Setoran Simpanan',
            'Pembayaran Angsuran',
            'Pendapatan Lain-lain',
        ],

        'expense' => [
            'Penarikan Simpanan',
            'Pencairan Pinjaman',
            'Biaya Operasional',
            'Pengeluaran Lain-lain',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Kategori Dampak Kas dari Modul Sumber
    |--------------------------------------------------------------------------
    |
    | Digunakan saat modul Simpanan, Pinjaman, atau Angsuran mencatat dampak kas
    | secara otomatis. Nilai wajib ada pada daftar kategori di atas.
    |
    */

    'source_categories' => [
        'savings_deposit' => 'Setoran Simpanan',
        'savings_withdrawal' => 'Penarikan Simpanan',
        'loan_disbursement' => 'Pencairan Pinjaman',
        'installment_payment' => 'Pembayaran Angsuran',
    ],

    /*
    |--------------------------------------------------------------------------
    | Setoran Kasir
    |--------------------------------------------------------------------------
    |
    | Tujuan setoran (kas pusat atau rekening bank tertentu) belum dikonfirmasi,
    | sehingga setoran dicatat sebagai perpindahan kas internal dengan kategori
    | tetap dan keterangan bebas dari operator.
    |
    */

    'deposit' => [
        'category' => 'Setoran Kasir',
    ],

    /*
    |--------------------------------------------------------------------------
    | Prefiks Nomor
    |--------------------------------------------------------------------------
    */

    'prefixes' => [
        'session' => 'SESI',
        'income' => 'KMASUK',
        'expense' => 'KKELUAR',
        'deposit' => 'KSETOR',
    ],

];
