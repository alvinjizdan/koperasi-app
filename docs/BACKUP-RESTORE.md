# BACKUP & RESTORE — PostgreSQL (KSP Bima Sakti Mandiri)

> Prosedur ini **telah diuji** pada environment lokal: `pg_dump` format custom → restore ke database
> terpisah → verifikasi jumlah baris tabel kunci identik (users=8, roles=6, permissions=41, settings=9).
> Versi tool yang diuji: PostgreSQL 18.4 (server juga 18.4).

## 1. Kebutuhan Backup

| Aspek | Keputusan (perlu konfirmasi pemilik aplikasi) |
|---|---|
| RPO (kehilangan data maksimum) | BELUM DITETAPKAN — usulan: maks 24 jam (backup harian). |
| RTO (waktu pulih maksimum) | BELUM DITETAPKAN — usulan: maks 4 jam kerja. |
| Frekuensi | Usulan: harian (malam, setelah jam operasional) + sebelum setiap deployment. |
| Retensi | Usulan: harian 14 hari, mingguan 8 minggu, bulanan 12 bulan. |
| Lokasi | Usulan: disk backup lokal server + salinan off-server (disk eksternal/cloud storage koperasi). |
| Penanggung jawab | NEEDS CONFIRMATION — nama & kontak operator. |

## 2. Hak Akses Database

Jangan memakai superuser (`postgres`) untuk aplikasi runtime. Buat dua peran:

```sql
-- User runtime aplikasi: hanya database produksi, tanpa hak membuat/menghapus database.
CREATE ROLE ksp_app LOGIN PASSWORD '<dari-secret-store>';
GRANT CONNECT ON DATABASE ksp_bima_sakti TO ksp_app;
GRANT USAGE ON SCHEMA public TO ksp_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO ksp_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO ksp_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO ksp_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO ksp_app;

-- User backup: hanya baca (pg_dump memerlukan SELECT + USAGE schema).
CREATE ROLE ksp_backup LOGIN PASSWORD '<dari-secret-store>';
GRANT CONNECT ON DATABASE ksp_bima_sakti TO ksp_backup;
GRANT USAGE ON SCHEMA public TO ksp_backup;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO ksp_backup;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO ksp_backup;

-- User migration (dipakai hanya saat deploy): DDL pada schema public.
CREATE ROLE ksp_migrate LOGIN PASSWORD '<dari-secret-store>';
GRANT CONNECT ON DATABASE ksp_bima_sakti TO ksp_migrate;
GRANT USAGE, CREATE ON SCHEMA public TO ksp_migrate;
-- + hak DDL tabel (CREATE/ALTER/DROP TABLE) sesuai kebutuhan migration.
```

Catatan: `php artisan migrate` saat deploy memakai `ksp_migrate`; koneksi runtime `.env` memakai `ksp_app`.
Partial unique index (mis. `cashier_sessions_operator_open_unique`, `cash_transactions_source_unique`)
ikut ter-backup/restore otomatis karena bagian definisi schema.

## 3. Prosedur Backup

```bash
# Variabel (isi tanpa mencatat password di shell history: gunakan PGPASSWORD session)
export PGPASSWORD='<password ksp_backup>'
PG_DUMP="/usr/lib/postgresql/18/bin/pg_dump"   # sesuaikan path server
STAMP=$(date +%Y%m%d_%H%M%S)
DEST=/var/backups/ksp-bima-sakti               # sesuaikan lokasi server

mkdir -p "$DEST"
"$PG_DUMP" -h 127.0.0.1 -p 5432 -U ksp_backup \
  -d ksp_bima_sakti -F c -f "$DEST/ksp_bima_sakti_${STAMP}.dump"

# Verifikasi file tidak kosong dan format valid
"$PG_DUMP" --version >/dev/null
ls -la "$DEST/ksp_bima_sakti_${STAMP}.dump"     # ukuran > 0
unset PGPASSWORD
```

- **Format:** `custom` (`-F c`) → mendukung restore selektif dan kompresi.
- **Penamaan:** `ksp_bima_sakti_YYYYMMDD_HHMMSS.dump`.
- **Penempatan:** direktori backup khusus, **di luar** webroot proyek.
- **Pengamanan:** file memuat hash password pengguna → batasi izin `chmod 600`, dan
  **enkripsi saat disalin off-server** (mis. `age`/`gpg` dengan kunci operator).
- **Retensi:** hapus backup di luar masa retensi (cron contoh: `find "$DEST" -name '*.dump' -mtime +14 -delete` untuk tier harian).

## 4. Prosedur Restore (ke database terpisah — selalu uji dulu di sini)

```bash
export PGPASSWORD='<password admin restore>'
PSQL="/usr/lib/postgresql/18/bin/psql"
PG_RESTORE="/usr/lib/postgresql/18/bin/pg_restore"

# 1) Buat database uji terpisah (JANGAN menimpa database produksi)
"$PSQL" -h 127.0.0.1 -U postgres -d postgres \
  -c "DROP DATABASE IF EXISTS ksp_restore_verify;" \
  -c "CREATE DATABASE ksp_restore_verify;"

# 2) Restore dump ke database uji
"$PG_RESTORE" -h 127.0.0.1 -U postgres -d ksp_restore_verify --no-owner \
  "$DEST/ksp_bima_sakti_${STAMP}.dump"

# 3) Verifikasi integritas (bandingkan dengan database sumber)
"$PSQL" -h 127.0.0.1 -U postgres -d ksp_restore_verify -t -A -c \
  "SELECT 'users='||(SELECT count(*) FROM users)||', roles='||(SELECT count(*) FROM roles)||', permissions='||(SELECT count(*) FROM permissions)||', settings='||(SELECT count(*) FROM settings);"
# → harus identik dengan hasil query yang sama pada database sumber.

# 4) Bersihkan database uji setelah verifikasi
"$PSQL" -h 127.0.0.1 -U postgres -d postgres -c "DROP DATABASE ksp_restore_verify;"
unset PGPASSWORD
```

**Restore darurat ke produksi** (hanya saat insiden, oleh operator berwenang):
1. Hentikan service aplikasi (cegah penulisan baru).
2. Backup dulu kondisi terkini (`pg_dump`) agar keadaan pra-restore tidak hilang.
3. Restore ke database baru, verifikasi integritas seperti di atas.
4. Tukar koneksi aplikasi ke database hasil restore (ubah `DB_DATABASE`), atau rename database.
5. Nyalakan aplikasi, jalankan smoke test (DEPLOYMENT-RUNBOOK.md §Smoke Test).

## 5. Dokumentasi Pemulihan (dalam insiden)

| Langkah | Detail |
|---|---|
| 1. Deteksi | Alert/log (lihat PRODUCTION-READINESS.md bagian monitoring). |
| 2. Keputusan | Picu RTO clock; komunikasi ke pengurus koperasi. |
| 3. Aksi | Ikuti §4 di atas (restore darurat). |
| 4. Verifikasi | Smoke test + cek laporan saldo vs backup sebelum insiden. |
| 5. Post-mortem | Catat penyebab & perbaikan prosedur. |

## 6. Hal yang BELUM diuji

- Restore ke versi PostgreSQL mayor berbeda (hanya 18.4 yang diuji).
- Backup fisik (base backup + WAL archiving/PITR) — usulan untuk fase produksi bila RPO ketat.
- Otomasi backup terjadwal di server (dijalankan operator setelah server tersedia).
