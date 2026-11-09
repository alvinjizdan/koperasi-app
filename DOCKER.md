# Docker Demo & Production Stack — KSP Bima Sakti Mandiri

Stack kontainer mandiri: **Nginx + PHP-FPM + PostgreSQL + ngrok (opsional)** untuk deployment lokal, staging, maupun demo portofolio.
Mendukung seluruh fitur aplikasi: Akuntansi Koperasi, Kasir, Pinjaman SHM, Pengajuan Multi-Type (SHGB & Buyback), Dokumen Legalitas Buyback, dan Approval Workflow Memorandum.

---

## 1. Prasyarat

- Docker Engine / Docker Desktop (v20+ dengan Docker Compose v2+): `docker info` harus berhasil.
- RAM minimal yang disarankan: 2 GB (untuk multi-stage Vite build dan PostgreSQL).
- Akun ngrok gratis (hanya jika memerlukan URL publik HTTPS): https://dashboard.ngrok.com.

---

## 2. Setup Awal & Inisialisasi

```bash
# 1) Siapkan file environment Docker dari template (JANGAN PERNAH commit .env.docker!)
cp .env.docker.example .env.docker

# 2) Konfigurasikan variabel wajib di .env.docker:
#    - APP_KEY           -> Buat via host: php artisan key:generate --show
#    - DB_PASSWORD & POSTGRES_PASSWORD (samakan keduanya dengan password yang kuat)
#    - APP_URL           -> http://localhost:8080 (atau URL ngrok jika menggunakan tunnel)

# 3) Build Docker image (multi-stage build mencakup Vite frontend assets & PHP-FPM dependencies):
docker compose build

# 4) Jalankan stack container (database PostgreSQL memiliki healthcheck sebelum app start):
docker compose up -d

# 5) Jalankan migrasi database secara terkontrol:
#    Cara A: Jalankan langsung via artisan di container app:
docker compose exec app php artisan migrate --force

#    Cara B: Atau set RUN_MIGRATIONS=true di .env.docker lalu recreate container:
#    docker compose up -d --force-recreate app

# 6) Seed data awal (roles, permissions, settings, superadmin) secara idempoten:
docker compose exec app php artisan db:seed --class=PermissionSeeder --force
docker compose exec app php artisan db:seed --class=RoleSeeder --force
docker compose exec app php artisan db:seed --force
#    Kredensial default: superadmin@ksp.local / password (SEGERA ganti setelah login awal).

# 7) Buka aplikasi di browser:
#    http://localhost:8080
```

---

## 3. Ngrok Tunnel HTTPS Publik (Opsional)

Jika ingin membagikan akses demo ke penguji luar atau client tanpa port forwarding:

```bash
# 1) Isi NGROK_AUTHTOKEN pada .env.docker Anda.
# 2) Sesuaikan APP_URL pada .env.docker ke domain ngrok (misal: https://xxxx.ngrok-free.app).
# 3) Jalankan service ngrok dengan profile "tunnel":
docker compose --profile tunnel up -d

# 4) Periksa URL publik yang diberikan:
docker compose logs ngrok
# atau buka web inspector ngrok lokal di: http://localhost:4040

# 5) Untuk menghentikan tunnel ngrok:
docker compose --profile tunnel stop ngrok
```

---

## 4. Persistensi Data & Dokumen Privat

Aplikasi menggunakan tiga named Docker volume:
1. **`db-data` (`/var/lib/postgresql/data`)**: Menyimpan seluruh tabel PostgreSQL (nasabah, pengajuan, pinjaman, jurnal akuntansi, sesi kasir, dan memorandum). Data aman saat container di-restart atau di-recreate.
2. **`app-storage` (`/var/www/html/storage`)**: Menyimpan berkas log, cache, dan **dokumen legalitas Buyback** (`storage/app/private/shm/memorandums/...`). Dokumen terlindungi dan tetap persisten.
3. **`nginx-root` (`/var/nginx-app`)**: Volume sinkronisasi aset publik antara PHP-FPM dan Nginx.

> [!CAUTION]
> Jangan pernah menjalankan `docker compose down -v` pada server yang memiliki data operasional, karena flag `-v` akan menghapus seluruh named volume secara permanen.

---

## 5. Prosedur Backup & Restore Database

### Backup Database:
```bash
# Dump database PostgreSQL dari container db ke file lokal host:
docker compose exec db pg_dump -U ksp_docker -d ksp_bima_sakti_docker -F c -b -v -f /tmp/backup.dump
docker compose cp db:/tmp/backup.dump ./backup_$(date +%Y%m%d_%H%M%S).dump
docker compose exec db rm -f /tmp/backup.dump
```

### Restore Database:
```bash
# Salin file backup ke container lalu jalankan pg_restore:
docker compose cp ./backup_file.dump db:/tmp/restore.dump
docker compose exec db pg_restore -U ksp_docker -d ksp_bima_sakti_docker -v --clean --if-exists /tmp/restore.dump
docker compose exec db rm -f /tmp/restore.dump
```

---

## 6. Operasional, Pemeliharaan & Troubleshooting

### Perintah Berguna:
```bash
# Memeriksa status dan kesehatan container:
docker compose ps

# Memeriksa log aplikasi Laravel dan web server Nginx:
docker compose logs -f app nginx

# Memeriksa status migrasi database:
docker compose exec app php artisan migrate:status

# Membersihkan dan merefresh cache konfigurasi/rute:
docker compose exec app php artisan config:clear
docker compose exec app php artisan route:clear
docker compose exec app php artisan view:clear

# Menghentikan seluruh container (data tetap tersimpan aman di volume):
docker compose down

# Menjalankan kembali container:
docker compose up -d
```

### Panduan Troubleshooting:
| Gejala | Penyebab Umum | Solusi |
|---|---|---|
| `docker compose up` error: `.env.docker not found` | File konfigurasi belum disalin | `cp .env.docker.example .env.docker` dan isi variabelnya. |
| Container `app` restart terus-menerus: *"PostgreSQL tidak merespons"* | Kredensial `DB_PASSWORD` dan `POSTGRES_PASSWORD` tidak sama | Samakan nilai password di `.env.docker`. |
| Error HTTP 500: *"No application encryption key"* | `APP_KEY` belum diset | Buat key baru (`php artisan key:generate --show`) dan masukkan ke `APP_KEY` di `.env.docker`. |
| Perubahan kode atau aset frontend belum tampak | Container masih menggunakan image lama | Jalankan `docker compose build --no-cache && docker compose up -d`. |
| File `.env` atau direktori sensitif diakses via browser | Ditolak oleh konfigurasi Nginx | Perilaku keamanan standar: Nginx secara otomatis memblokir file tersembunyi (`.env`, `.git`) dengan status 403/404. |

---

## 7. Arsitektur Keamanan Docker

- **Non-Root & Privilese Minimum**: PHP-FPM berjalan sebagai `www-data`, file source code dilindungi izin baca ketat.
- **Isolasi Database**: Port PostgreSQL (`5432`) hanya diekspos ke jaringan internal bridge (`internal`), tidak terekspos ke internet publik.
- **Zero Secrets in Image**: Image dibangun tanpa menyertakan `.env` atau kunci rahasia apa pun (`.dockerignore` mengecualikan seluruh file konfigurasi rahasia).
- **Perlindungan Dokumen Privat**: Dokumen PDF legalitas Buyback disimpan di luar web root publik (`storage/app/private/`) dan hanya dapat diunduh melalui controller Laravel berotorisasi (`buybackDownloadDocument`).
| CSS/JS 404 | Build aset tidak ikut | Pastikan `docker compose build` ulang; cek `docker compose exec app ls public/build` |
| Login gagal setelah ganti APP_URL | Cookie domain/skema | Samakan `APP_URL` dengan URL aktif; untuk HTTPS ngrok set `SESSION_SECURE_COOKIE=true` |
| ngrok error `ERR_NGROK_108` | Authtoken kosong/salah | Isi `NGROK_AUTHTOKEN` valid di `.env.docker`, lalu `docker compose --profile tunnel up -d --force-recreate ngrok` |
| Warning otomatis Laravel: `APP_URL` != host ngrok | APP_URL masih localhost | Ubah `APP_URL` ke URL tunnel (menghilangkan warn; tidak wajib) |
