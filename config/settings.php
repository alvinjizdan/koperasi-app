<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Definisi Pengaturan Aplikasi
    |--------------------------------------------------------------------------
    |
    | Kunci, nilai default, dan metadata tampilan untuk halaman Pengaturan.
    | Hanya kunci yang benar-benar dipakai aplikasi yang didefinisikan di sini;
    | tidak ada konfigurasi kosong. Nilai tersimpan pada tabel settings.
    |
    | Zona waktu operasional dipakai modul Laporan/Dashboard melalui
    | config('reports.operational_timezone'). Format tanggal dan mata uang
    | dipakai sebagai preferensi tampilan dan tidak pernah mengubah cara
    | penyimpanan nominal transaksi (decimal 15,2) maupun data historis.
    |
    */

    'keys' => [
        'coop_name' => 'KSP Bima Sakti Mandiri',
        'coop_address' => 'Jalan Citaland Boulevard Ruko C2 Pamengkang Mundu, Kab. Cirebon 45173',
        'coop_phone' => '',
        'coop_email' => '',
        'coop_legal_number' => 'AHU-0012817.AH.01.26.TAHUN.2021',
        'coop_tagline' => 'Koperasi Simpan Pinjam',

        'operational_timezone' => 'Asia/Jakarta',
        'date_format' => 'd/m/Y',
        'currency_code' => 'IDR',
    ],

    'definitions' => [
        'coop_name' => [
            'group' => 'profil',
            'label' => 'Nama Koperasi',
            'type' => 'text',
            'required' => true,
        ],
        'coop_address' => [
            'group' => 'profil',
            'label' => 'Alamat',
            'type' => 'textarea',
            'required' => true,
        ],
        'coop_phone' => [
            'group' => 'profil',
            'label' => 'Nomor Telepon',
            'type' => 'text',
            'required' => false,
        ],
        'coop_email' => [
            'group' => 'profil',
            'label' => 'Email Resmi',
            'type' => 'email',
            'required' => false,
        ],
        'coop_legal_number' => [
            'group' => 'profil',
            'label' => 'Nomor Badan Hukum / Legalitas',
            'type' => 'text',
            'required' => false,
            'help' => 'Perubahan legalitas hanya boleh dilakukan melalui dokumen resmi; isi dengan cermat.',
        ],
        'coop_tagline' => [
            'group' => 'profil',
            'label' => 'Tagline / Deskripsi Singkat',
            'type' => 'text',
            'required' => false,
        ],

        'operational_timezone' => [
            'group' => 'operasional',
            'label' => 'Zona Waktu Operasional',
            'type' => 'select',
            'options' => [
                'Asia/Jakarta' => 'Asia/Jakarta (WIB)',
                'Asia/Makassar' => 'Asia/Makassar (WITA)',
                'Asia/Jayapura' => 'Asia/Jayapura (WIT)',
                'UTC' => 'UTC',
            ],
            'help' => 'Dipakai sebagai dasar hari operasional pada laporan dan dashboard. Tidak mengubah nilai historis.',
        ],
        'date_format' => [
            'group' => 'operasional',
            'label' => 'Format Tanggal Tampilan',
            'type' => 'select',
            'options' => [
                'd/m/Y' => '25/09/2026',
                'd-m-Y' => '25-09-2026',
                'Y-m-d' => '2026-09-25',
                'd F Y' => '25 September 2026',
            ],
        ],
        'currency_code' => [
            'group' => 'operasional',
            'label' => 'Kode Mata Uang',
            'type' => 'select',
            'options' => [
                'IDR' => 'IDR — Rupiah',
            ],
            'help' => 'Nominal transaksi tetap disimpan sebagai decimal(15,2) terlepas dari format tampilan.',
        ],
    ],
];
