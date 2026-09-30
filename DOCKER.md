# Docker Demo Stack — KSP Bima Sakti Mandiri

Stack lokal: **Nginx + PHP-FPM + PostgreSQL + ngrok (opsional)** untuk demo & portofolio.
Semua konfigurasi Vercel telah dihapus (lihat riwayat Ticket 31–34 sebelumnya).

## 1. Prasyarat

- Docker Desktop (engine Linux) berjalan: `docker info` harus sukses
- Akun ngrok gratis (hanya bila butuh URL publik HTTPS): https://dashboard.ngrok.com

## 2. Setup

```bash
# 1) Siapkan environment (jangan commit .env.docker!)
cp .env.docker.example .env.docker

# 2) Isi minimal:
#    - APP_KEY        -> php artisan key:generate --show  (dari mesin host, pakai PHP lokal)
#    - DB_PASSWORD & POSTGRES_PASSWORD (sama, bukan default)
#    - APP_URL        -> http://localhost:8080 (atau URL ngrok nanti)

# 3) Build image
docker compose build

# 4) Jalankan stack (db menunggu healthcheck sebelum app start)
docker compose up -d

# 5) Migrasi SEKALI secara terkontrol (tidak pernah otomatis):
#    set RUN_MIGRATIONS=true di .env.docker lalu:
docker compose up -d --force-recreate app
#    ...tunggu log "[entrypoint] Selesai migrate", lalu kembalikan RUN_MIGRATIONS=false:
#    docker compose up -d --force-recreate app

# 6) Seed data awal (roles/permissions/settings + superadmin) — idempoten:
docker compose exec app php artisan db:seed --force
#    Login awal: superadmin@ksp.local / password  -> SEGERA ganti passwordnya.

# 7) Akses: http://localhost:8080
```

## 3. Ngrok tunnel HTTPS publik (opsional)

```bash
# 1) Ambil authtoken: https://dashboard.ngrok.com/get-started/your-authtoken
#    Isi NGROK_AUTHTOKEN di .env.docker (jangan pernah di-commit).

# 2) Arahkan APP_URL ke URL publik di .env.docker, mis.:
#    APP_URL=https://xxxx.ngrok-free.app
#    (opsional hardening: SESSION_SECURE_COOKIE=true)

# 3) Mulai tunnel (profile "tunnel"):
docker compose --profile tunnel up -d

# 4) URL publik tampil di:
docker compose logs ngrok        # cari baris "url=https://....ngrok-free.app"
#    atau inspector lokal: http://localhost:4040

# 5) Hentikan tunnel:
docker compose --profile tunnel stop ngrok
#    atau matikan total: docker compose --profile tunnel down
```

Catatan URL gratis ngrok:
- URL berubah **setiap kali tunnel dibuat ulang** (restart/container recreate).
- Untuk domain statis dibutuhkan akun berbayar (isi `NGROK_DOMAIN`).
- Halaman peringatan browser ngrok muncul pada kunjungan pertama (klik "Visit Site").
- Trafik: internet -> ngrok -> nginx:80 -> FastCGI app:9000. **PostgreSQL tidak pernah terekspos publik** (tanpa mapping port, hanya jaringan internal).

## 4. Perintah berguna

```bash
docker compose ps                      # status + healthcheck
docker compose logs -f app nginx       # log aplikasi & web server
docker compose exec app php artisan psql:test 2>/dev/null || docker compose exec db psql -U ksp_docker -d ksp_bima_sakti_docker -c "select 1;"
docker compose exec app php artisan migrate:status
# Test suite dijalankan dari HOST (phpunit/tests tidak ikut dalam image production):
php artisan test --compact
docker compose down                    # stop (volume data tetap aman)
docker compose down -v                 # HAPUS volume db — destruktif, jangan sembarangan
```

## 5. Arsitektur & keamanan

| Komponen | Detail |
|---|---|
| `app` | PHP 8.4-FPM (composer `php: ^8.3`), ekstensi pdo_pgsql/intl/bcmath/gd/opcache, `storage/` di named volume |
| `nginx` | Document root = salinan `public/` (volume `nginx-root`, disinkron entrypoint app); aset `/build/*` immutable; file `.env`/dotfile/sqlite/log **ditolak (404)**; PHP selalu via FastCGI (source code tidak pernah dilayani mentah) |
| `db` | PostgreSQL 18-alpine, `PGDATA` di named volume `db-data`, **tanpa port publik**, healthcheck `pg_isready` |
| `ngrok` | Profile `tunnel`; token via env `NGROK_AUTHTOKEN`; config `docker/ngrok/ngrok.yml` tanpa token |
| Secrets | Hanya di `.env.docker` (di-gitignore). Image dibangun tanpa `.env*` (`.dockerignore`). |
| HTTPS | ngrok terminasi TLS; `TRUST_PROXIES=*` + `real_ip` nginx meneruskan host/proto asli ke Laravel |
| Migrasi | Manual via `RUN_MIGRATIONS=true` (sekali) — tidak pernah otomatis per start/request |

## 6. Troubleshooting

| Gejala | Sebab umum | Solusi |
|---|---|---|
| `docker compose up` gagal: env_file tidak ada | `.env.docker` belum dibuat | `cp .env.docker.example .env.docker` |
| App loop restart: "PostgreSQL tidak merespons" | DB_PASSWORD/POSTGRES_PASSWORD tidak konsisten, atau volume lama dengan kredensial beda | Samakan kredensial; bila volume memang boleh di-reset: `docker compose down -v && docker compose up -d` |
| Halaman 500 + log `No application encryption key` | `APP_KEY` kosong | Isi `APP_KEY=base64:...` (host: `php artisan key:generate --show`) lalu recreate app |
| CSS/JS 404 | Build aset tidak ikut | Pastikan `docker compose build` ulang; cek `docker compose exec app ls public/build` |
| Login gagal setelah ganti APP_URL | Cookie domain/skema | Samakan `APP_URL` dengan URL aktif; untuk HTTPS ngrok set `SESSION_SECURE_COOKIE=true` |
| ngrok error `ERR_NGROK_108` | Authtoken kosong/salah | Isi `NGROK_AUTHTOKEN` valid di `.env.docker`, lalu `docker compose --profile tunnel up -d --force-recreate ngrok` |
| Warning otomatis Laravel: `APP_URL` != host ngrok | APP_URL masih localhost | Ubah `APP_URL` ke URL tunnel (menghilangkan warn; tidak wajib) |
