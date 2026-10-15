<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Ticket 99 — sweep Promise to Pay (pilihan teknis, bukan aturan bisnis)
|--------------------------------------------------------------------------
| Aturan PTP hanya berbasis tanggal (promised_date < hari ini → MISSED),
| sehingga sekali sehari tepat setelah pergantian tanggal (00:05 UTC —
| timezone aplikasi) sudah menangkap seluruh kandidat; sifat idempoten
| membuat frekuensi lebih sering pun tidak mengubah arti aturan.
| Frekuensi automation Collection LAIN (cadence follow-up, reminder/
| notifikasi, aksi berbasis aging) tetap PENDING BUSINESS CONFIRMATION pada
| contract (`automatic_scheduler_or_job`). Scheduler berjalan via cron
| server `php artisan schedule:run` — TIDAK bergantung pada user membuka
| halaman.
 */
Schedule::command('collection:ptp-missed')->dailyAt('00:05');
