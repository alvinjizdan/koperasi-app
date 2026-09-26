# ROLLBACK RUNBOOK — KSP Bima Sakti Mandiri

> Untuk kondisi kegagalan setelah deployment. **Prinsip utama: rollback kode TIDAK otomatis
> membatalkan perubahan database** (migration bisa destruktif). Perlakukan keduanya sebagai
> keputusan terpisah.

## 1. Pemicu Rollback

Rollback aplikasi dipicu bila salah satu terjadi setelah release:

- Health check `/up` gagal atau smoke test gagal di langkah manapun.
- Error rate tinggi di log (`ERROR`/`CRITICAL` berulang pada alur utama).
- Laporan/dashboard menampilkan angka yang tidak konsisten dengan transaksi.
- Perilaku keuangan yang salah terlihat (saldo, kas, angsuran).

Putusan rollback: operator + penanggung jawab aplikasi (NEEDS CONFIRMATION nama penanggung jawab).

## 2. Hentikan Release yang Bermasalah

```bash
# 1) Mode maintenance agar pengguna tidak menulis data baru selama penanganan
php artisan down --retry=60

# 2) Jika perlu memutus sementara dari traffic
sudo systemctl stop php8.3-fpm    # sesuaikan nama service
```

> `php artisan down` harus dipakai SEBELUM tindakan database apa pun untuk mencegah transaksi baru.

## 3. Kembalikan Versi Aplikasi

```bash
# Dengan struktur releases/current (disarankan di DEPLOYMENT-RUNBOOK §4 langkah 8):
ln -sfn /var/www/ksp/releases/<versi-sebelumnya> /var/www/ksp/current
sudo systemctl reload php8.3-fpm

# Atau, bila deploy langsung satu direktori:
git checkout <tag-sebelumnya>
composer install --no-dev --optimize-autoloader
npm ci && npm run build       # hanya jika aset ikut berubah
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo systemctl reload php8.3-fpm
```

Lalu **jalankan health check + smoke test** (DEPLOYMENT-RUNBOOK §5) untuk memverifikasi
versi lama sehat sebelum buka akses.

```bash
php artisan up
```

## 4. Migration yang Tidak Bisa Di-rollback

- `php artisan migrate:rollback` hanya aman bila migration terakhir punya method `down()` yang benar.
- **Jangan** menjalankan `migrate:rollback` di produksi tanpa meninjau file migration yang terlibat.
- Jika release memuat migration **baru** dan rollback kode dilakukan:
  1. Cek `migrations` tabel: `SELECT * FROM migrations ORDER BY id DESC LIMIT 10;`
  2. Jika migration baru **tidak destruktif** (tabel/kolom baru yang tidak dipakai kode lama): boleh dibiarkan — kode lama tetap jalan.
  3. Jika migration baru **destruktif** (rename/drop kolom yang dipakai kode lama): kode lama TIDAK akan jalan → gunakan jalur restore database (§5), bukan `migrate:rollback`.
- Catat di file migration berapa yang memerlukan perhatian khusus saat code review release (proses ke depan).

## 5. Pemulihan Database (bila data rusak)

Ikuti `docs/BACKUP-RESTORE.md §4` (restore darurat). Ringkasan:

1. Pastikan aplikasi tetap dalam mode maintenance.
2. Backup dulu kondisi terkini untuk forensik.
3. Restore dump pre-deploy ke database terpisah → verifikasi integritas.
4. Arahkan aplikasi ke database hasil restore (ubah `DB_DATABASE` di `.env` + `config:clear`).
5. **Verifikasi versi kode vs schema:** kode yang kembali harus kompatibel dengan schema dari
   backup pre-deploy (inilah alasan urutan: rollback kode dulu §3, baru restore database §5).

## 6. Verifikasi Setelah Rollback

| Item | Cara |
|---|---|
| Aplikasi hidup | `/up` 200, login sukses. |
| Versi kode | `php artisan about` / cek direktori release aktif. |
| Versi schema | Query tabel `migrations` (batch terakhir sesuai versi lama). |
| Data | Smoke test transaksi uji + laporan vs catatan pre-insiden. |
| Log | Tidak ada ERROR baru setelah rollback. |

## 7. Setelah Rollback

1. Dokumentasikan insiden: penyebab, durasi, dampak data (jika ada).
2. Perbaiki penyebab di environment development; ulangi seluruh validasi release.
3. Jadwalkan release ulang melalui DEPLOYMENT-RUNBOOK dari awal.
