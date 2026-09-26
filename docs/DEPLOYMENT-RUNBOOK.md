# DEPLOYMENT RUNBOOK — KSP Bima Sakti Mandiri

> Runbook ini untuk operator yang menjalankan release ke server produksi.
> **Prinsip:** deployment hanya boleh dilakukan setelah blocker di PRODUCTION-READINESS.md
> ditandai selesai oleh pemilik aplikasi. Runbook ini TIDAK dieksekusi otomatis.

## 1. Arsitektur Target (provider-agnostic)

```
[Internet] → HTTPS 443 → [Reverse proxy: Nginx/Caddy/IIS] → [PHP-FPM (Laravel) :9000]
                                                              ├─ PostgreSQL 18 (internal, TIDAK terekspos internet)
                                                              ├─ Storage lokal: storage/ + backup terpisah
                                                              └─ Log: daily files + log server
```

- **Satu server minimal memenuhi** (beban MVP koperasi, satu kantor):
  - 2 vCPU / 4 GB RAM / 40 GB disk (OS+app) + lokasi backup terpisah.
  - PHP ≥ 8.3 dengan ekstensi: `pdo_pgsql`, `mbstring`, `openssl`, `bcmath`, `intl`, `gd` (semua terverifikasi pada PHP 8.5 lokal).
  - Node.js ≥ 20 (hanya untuk build; bisa dilakukan di mesin CI/lokal, tidak wajib di server).
  - PostgreSQL 18 (versi yang teruji; 14+ secara teknis kompatibel schema — uji dulu bila memakai versi lain).
- **Environment terpisah:** development (lokal) / testing (SQLite :memory: via phpunit.xml) / production (server ini). Jangan pernah mengarahkan `.env` produksi ke database development.

## 2. Jaringan

| Item | Kebutuhan |
|---|---|
| Publik | 443/HTTPS saja (80 untuk redirect ke HTTPS). |
| Database | Hanya localhost/internal network. **Jangan buka port 5432 ke internet.** |
| Administratif | SSH dibatasi IP kantor/operator; bukan password, gunakan kunci. |
| SMTP | Outbound ke server mail (reset password). |
| Update | Akses ke package repository saat provisioning. |

## 3. Prasyarat Sebelum Release

1. **Git checkpoint tersedia** — repo harus sudah memiliki commit (lihat blocker PR-01 di PRODUCTION-READINESS.md). Tanpa commit, langkah tag/rollback tidak bisa dijalankan.
2. Blocker PRODUCTION-READINESS.md berstatus selesai (UAT disetujui, rumus bunga dikonfirmasi, keputusan BUG-01).
3. `.env` produksi lengkap sesuai docs/PRODUCTION-ENVIRONMENT.md; `APP_DEBUG=false`; `APP_ENV=production`.
4. Backup database terbaru ada dan restore-nya pernah diverifikasi (BACKUP-RESTORE.md).
5. SSL certificate valid terpasang di reverse proxy.

## 4. Langkah Deployment

```bash
# --- Di mesin build (bisa lokal/CI) ---
git checkout <tag-release>                 # 1-2. branch & commit release terverifikasi
git status --short                         # harus bersih
composer install --no-dev --optimize-autoloader
npm ci && npm run build                    # 4. build produksi (public/build)

# --- Di server produksi ---
# 5. Environment variable: pastikan .env sesuai PRODUCTION-ENVIRONMENT.md (sekali saja saat provisioning)

# 6-7. Persiapan database + backup sebelum perubahan
#   (ikuti docs/BACKUP-RESTORE.md §3 — WAJIB sebelum migrate)

# 8. Release kode
#   Salin artefak build (kode + vendor + public/build) ke direktori release,
#   lalu symlink/switch (mis. struktur releases/current) agar rollback cepat.

# 9. Migration (hanya yang disetujui; ulangi tinjauan jika ada migration baru)
php artisan migrate --force

# 10. Cache & optimasi
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# 11. Restart service
sudo systemctl reload php8.3-fpm   # sesuaikan versi
sudo systemctl reload nginx        # sesuaikan reverse proxy
# Tidak ada queue worker/scheduler yang wajib berjalan saat ini (lihat §6).
```

## 5. Health Check & Smoke Test

**Health check:** `GET https://<domain>/up` → ekspektasi HTTP 200 dengan body minimal
(health route bawaan Laravel 13 di `bootstrap/app.php`; tidak mengekspos konfigurasi).

**Smoke test (jalankan berurutan, catat hasil):**

| # | Aksi | Ekspektasi |
|---|---|---|
| 1 | Buka `https://<domain>/` | Redirect ke halaman login, tanpa error. |
| 2 | Login superadmin uji | Masuk ke dashboard, angka nol/tidak error. |
| 3 | Buat anggota uji | Sukses, muncul di daftar. |
| 4 | Buka modul Simpanan → buka rekening → setoran uji | Saldo ter-update. |
| 5 | Buat pinjaman uji → approve → disburse (non-tunai jika menghindari dampak kas) | Status berubah sesuai alur. |
| 6 | Buka Laporan (simpanan & pinjaman) | Angka konsisten dengan transaksi uji. |
| 7 | Logout | Kembali ke login; akses langsung ke /dashboard ditolak. |

> Hapus/soft-disable data uji setelah smoke test atau gunakan tenant data terpisah sesuai kebijakan.

## 6. Background Processing (status saat ini)

- **Tidak ada job queue** (tidak ada `app/Jobs`), **tidak ada tugas scheduler** (`routes/console.php` kosong dari tugas terjadwal), `QUEUE_CONNECTION=database` hanya konfigurasi default.
- Konsekuensi: **tidak ada queue worker atau cron `schedule:run` yang wajib** saat ini.
- Bila kelak ditambahkan job/scheduler, perbarui runbook ini (worker systemd + cron `php artisan schedule:run`) DAN verifikasi idempotensi job keuangan sebelum aktif.

## 7. Verifikasi Log

```bash
tail -f storage/logs/laravel-$(date +%F).log   # channel daily
# Yang diamati saat smoke test:
# - tidak ada ERROR/CRITICAL baru
# - request login/anggota/simpanan tercatat normal
```

Perhatikan juga log reverse proxy (access/error) dan log PostgreSQL
(`log_min_duration_statement` disarankan diaktifkan untuk query lambat).

## 8. Setelah Release

1. Isi checklist validasi di PRODUCTION-READINESS.md §Release Validation.
2. Tag commit release di Git (`git tag vX.Y.Z`).
3. Simpan catatan release (tanggal, commit, hasil smoke test, hasil backup pre-deploy).
