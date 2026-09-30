<?php

use App\Support\PerPage;

return [

    /*
    |--------------------------------------------------------------------------
    | Zona Waktu Operasional
    |--------------------------------------------------------------------------
    |
    | Batas periode laporan (hari ini, 7 hari terakhir, bulan ini, dan bulan
    | sebelumnya) dihitung memakai zona waktu ini. Bila dibiarkan null, aplikasi
    | mengikuti config('app.timezone') agar konsisten dengan data yang sudah ada.
    |
    | Untuk operasional KSP di Indonesia, set APP_OPERATIONAL_TIMEZONE=Asia/Jakarta.
    |
    */

    'operational_timezone' => env('APP_OPERATIONAL_TIMEZONE'),

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    |
    | Page size laporan kini diatur pengguna lewat ?per_page= dengan whitelist
    | yang sama seperti halaman lain (10/25/50/100, bawaan 25) — lihat
    | App\Support\PerPage.
    |
    */

    'per_page' => PerPage::DEFAULT,

    /*
    |--------------------------------------------------------------------------
    | Ekspor CSV
    |--------------------------------------------------------------------------
    |
    | Ekspor selalu mengikuti filter yang sedang aktif. Data pribadi (nomor
    | identitas dan nomor telepon) tidak diekspor karena lapisan izin berbasis
    | role belum tersedia di aplikasi ini.
    |
    */

    'export' => [
        'chunk_size' => 500,
        'include_personal_data' => false,
    ],

];
