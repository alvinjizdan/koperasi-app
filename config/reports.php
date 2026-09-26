<?php

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
    */

    'per_page' => 20,

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
