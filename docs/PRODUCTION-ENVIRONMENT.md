# PRODUCTION ENVIRONMENT — KSP Bima Sakti Mandiri

> Dokumen ini mendefinisikan seluruh variabel environment yang dibutuhkan saat deployment produksi.
> **Tidak ada secret nyata di dokumen ini.** Nilai secret dibuat di server dan hanya disimpan di
> sistem penyimpanan secret operator (lihat bagian "Penanganan Secret").

## 1. Variabel Wajib (kritis untuk produksi)

| Variabel | Nilai produksi yang disarankan | Deskripsi |
|---|---|---|
| `APP_ENV` | `production` | Mengaktifkan konfigurasi produksi Laravel. |
| `APP_DEBUG` | `false` | **Wajib false.** Jika true, stack trace dan konfigurasi bocor ke pengguna. |
| `APP_KEY` | string base64 32 byte, unik | Kunci enkripsi sesi & data terenkripsi. Generate: `php artisan key:generate --show`. JANGAN menyalin APP_KEY dari environment lain yang sudah memiliki data. |
| `APP_URL` | URL HTTPS domain final | Dipakai untuk aset, pagination, dan tautan email. |
| `APP_LOCALE` | `id` (disarankan) atau `en` | Bahasa antarmuka validasi & pesan. |
| `APP_OPERATIONAL_TIMEZONE` | `Asia/Jakarta` | Zona waktu operasional dashboard/laporan/kasir (jangan diubah tanpa konfirmasi). |
| `DB_CONNECTION` | `pgsql` | Driver PostgreSQL. |
| `DB_HOST` | host internal (bukan publik) | Lihat catatan jaringan di DEPLOYMENT-RUNBOOK. |
| `DB_PORT` | `5432` | Port PostgreSQL. |
| `DB_DATABASE` | nama database produksi | Contoh nama: `ksp_bima_sakti` (final oleh operator). |
| `DB_USERNAME` / `DB_PASSWORD` | user aplikasi least-privilege | BUKAN superuser — lihat BACKUP-RESTORE.md bagian "Hak akses database". Password dari secret store. |
| `SESSION_SECURE_COOKIE` | `true` | Cookie sesi hanya dikirim via HTTPS. |
| `SESSION_HTTP_ONLY` | `true` (default) | Cookie sesi tidak dapat dibaca JavaScript. |
| `SESSION_SAME_SITE` | `lax` (default) | Mitigasi CSRF pada cookie. |
| `SESSION_ENCRYPT` | `true` (disarankan) | Enkripsi payload cookie sesi. |
| `SESSION_DOMAIN` | domain produksi atau kosong | Kosongkan bila aplikasi pada satu host. |
| `LOG_CHANNEL` | `stack` | Saluran log utama. |
| `LOG_STACK` | `daily` | Rotasi harian (default retensi 14 hari; atur `LOG_DAILY_DAYS` bila perlu). |
| `LOG_LEVEL` | `info` | `debug` TIDAK boleh dipakai di produksi. |
| `CACHE_STORE` | `database` (atau `redis` bila tersedia) | Cache aplikasi. |
| `QUEUE_CONNECTION` | `database` | Saat ini tidak ada job terjadwal; tetap `database` agar aman bila kelak ditambahkan. |
| `BCRYPT_ROUNDS` | `12` (default) | Jangan diturunkan. |
| `MAIL_MAILER` | SMTP produksi terverifikasi | Wajib nyata untuk reset password. Jangan `log` di produksi. |
| `MAIL_HOST` / `MAIL_PORT` / `MAIL_USERNAME` / `MAIL_PASSWORD` | dari secret store | Kredensial SMTP. |
| `MAIL_FROM_ADDRESS` | alamat email koperasi | Pengirim reset password/verifikasi. |
| `VITE_APP_NAME` | `"${APP_NAME}"` | Satu-satunya variabel VITE; tidak membawa secret. |

## 2. Variabel Opsional / Kontekstual

| Variabel | Kapan dibutuhkan |
|---|---|
| `TRUSTED_PROXIES` | Hanya jika aplikasi di belakang reverse proxy/load balancer. Isi CIDR proxy internal. Tanpa ini Laravel tidak memercayai header `X-Forwarded-*`. |
| `TRUSTED_HEADERS` | Pasangan dengan TRUSTED_PROXIES (default Laravel sudah benar). |
| `REDIS_*` | Hanya bila CACHE/QUEUE dipindah ke Redis. Saat ini tidak dibutuhkan. |
| `AWS_*` / `S3` | Tidak dibutuhkan (filesystem lokal). |
| `APP_MAINTENANCE_DRIVER` | `file` (default) cukup untuk satu server. |

## 3. Daftar Secret Lengkap

Seluruh secret berikut harus berasal dari secret store, **tidak pernah** di-commit:

1. `APP_KEY` — enkripsi sesi/cookie.
2. `DB_PASSWORD` — database.
3. `MAIL_PASSWORD` — SMTP.
4. `REDIS_PASSWORD` — hanya bila Redis dipakai.

Cara verifikasi tidak ada secret lain: `grep -rn "APP_KEY\|PASSWORD\|SECRET" resources/js/ public/build/` harus tidak menghasilkan nilai nyata (sudah diverifikasi pada Ticket 12: bundle bersih).

## 4. Penanganan Secret

- Secret dibuat di server produksi atau diambil dari password manager operator saat provisioning; tidak dikirim via chat/email/tiket.
- File `.env` di server: `chmod 640`, pemilik user runtime PHP (bukan world-readable).
- `.gitignore` sudah mengecualikan `.env`, `.env.backup`, `.env.production`, `/storage/*.key` (terverifikasi).
- Rotasi: `APP_KEY` hanya dirotasi dengan prosedur khusus (data terenkripsi lama tidak terbaca bila key berganti tanpa re-encryption) — jangan dirotasi tanpa kebutuhan dan rencana migrasi.
- Backup database mengandung hash password pengguna → simpan terenkripsi (lihat BACKUP-RESTORE.md).

## 5. Cek Konfigurasi Setelah Deploy

```bash
php artisan config:clear && php artisan config:show APP_ENV APP_DEBUG SESSION_SECURE_COOKIE
# Ekspektasi: production, false, true
php artisan about   # tinjau bagian environment & cache
```
