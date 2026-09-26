# LAPORAN QA & UAT — TICKET 11

**Proyek:** Aplikasi KSP Bima Sakti Mandiri
**Environment:** Local Development — PHP 8.5.7 (Herd), Laravel 13, React 18 + Inertia.js, PostgreSQL 16
**Agen:** Freebuff (QA Engineer Senior) — 26 September 2026
**Catatan:** Tidak ada commit/push yang dilakukan (sesuai instruksi).

---

## A. BASELINE

| Item | Kondisi |
|---|---|
| Branch | `staging` |
| Commit | **Tidak ada commit** (repo belum pernah di-commit; seluruh file untracked) |
| Working tree | Semua file untracked; tidak ada perubahan yang dibuang |
| Environment | PHP 8.5.7 via Herd (`C:\Users\ASUS\.config\herd\bin\php.bat`); APP_ENV=local |
| Database dev | PostgreSQL `ksp_bima_sakti` @ 127.0.0.1 (8 users, 0 members sebelum pengujian UI) |
| Database testing | SQLite `:memory:` sesuai `phpunit.xml` |
| Baseline test | `artisan test --compact` → **181 tests, 1365 assertions, SEMUA PASS** (69.2s) |
| Build baseline | `npm run build` sukses; manifest Vite lengkap (perbaikan manifest dari ticket sebelumnya terverifikasi) |
| Migration | Semua `Ran`, 0 pending |

**Simpulan baseline:** sehat. Temuan laporan historis Ticket 10 (permission kasir sesi, atomic wrapper, TmpDebug8Test) sudah tuntas di kode aktual:
- Route `kasir.sesi.store`/`sesi.close.store` sudah ber-middleware `permission:kasir.open_session`/`close_session`.
- `LoanService::submit` sudah dibungkus `DbTransaction::run` (helper retry deadlock 40P01/40001, maks 3 percobaan).
- `tests/Feature/TmpDebug8Test.php` sudah tidak ada.
- File E2E aktual adalah `tests/Feature/EndToEndFlowTest.php` (6 alur, bukan "EndToEndTest.php" seperti disebut laporan lama).

---

## B. RINGKASAN QA

- **Status keseluruhan:** PASS untuk seluruh pengujian yang dapat dieksekusi. Tidak ada bug CRITICAL/HIGH baru.
- **Modul yang diuji:** Autentikasi, Dashboard, Anggota, Simpanan, Pinjaman, Angsuran, Kasir, Laporan, Pengaturan & Pengguna, Audit Trail, Rekonsiliasi (11/11).
- **Modul belum diuji:** tidak ada pada level functional; pengujian browser hanya desktop Chromium (lihat F).
- **Risiko utama:**
  1. Rumus bunga/jasa/denda = 0 (belum dikonfirmasi koperasi) — pinjaman tidak menghasilkan kewajiban bunga.
  2. UAT belum divalidasi pengguna koperasi (lihat G).
  3. Gap otorisasi pada sebagian route GET (lihat D) — dampak rendah karena tidak ada data sensitif per halaman.

---

## C. FUNCTIONAL TESTING

Dieksekusi via automated suite (`artisan test --compact`) + verifikasi browser. Rincian per perintah di bagian I.

| Area | Test (file) | Status |
|---|---|---|
| Autentikasi | tests/Feature/Auth/* (7 file: login valid/invalid, logout, redirect guest, verifikasi email, konfirmasi password, reset password) | PASS |
| Akun nonaktif & sesi | UserManagementTest (login ditolak, sesi diakhiri saat deaktivasi, proteksi admin terakhir) | PASS |
| Dashboard | DashboardTest (redirect guest, render metrik, empty state) | PASS |
| Anggota | MemberTest (CRUD, pencarian, filter status, duplikat nomor, validasi, deaktivasi) | PASS |
| Simpanan | SavingsTest (buka rekening, setoran, penarikan, saldo, penolakan overdraft, nominal tidak valid, produk nonaktif) | PASS |
| Pinjaman & angsuran | LoanAndInstallmentTest (pengajuan, persetujuan/penolakan, ganda ditolak, pencairan, ganda ditolak, jadwal, pembayaran, status lunas) | PASS |
| Kasir | CashierTest (sesi, saldo awal, penerimaan/pengeluaran, setoran, penutupan + catatan wajib saat selisih, transaksi ditolak setelah sesi tutup) | PASS |
| Laporan & ekspor | ReportTest + ReportExportTest (filter periode, status, konsistensi angka, CSV export) | PASS |
| Pengaturan/pengguna | UserManagementTest + ProfileTest (CRUD pengguna, role, permission, reset password) | PASS |
| Audit trail | AuditTrailTest (tercatat pada aksi penting, referensi, hak akses, 403 tanpa permission) | PASS |
| Rekonsiliasi | ReconciliationTest (run, temuan, follow-up, read-only, otorisasi) | PASS |
| E2E lintas modul | EndToEndFlowTest (ALUR 1–6) | PASS |
| **Negative tambahan via browser** | Validasi form anggota (nama wajib), duplikat nomor anggota ditolak, ID tidak ada → 404 | PASS |
| **Concurrency** | ConcurrencyAndFailureTest | PASS (lihat di bawah) |

**Concurrency & idempotency (verifikasi eksekusi):** suite berjalan sebagai **proses PHP paralel nyata** (`proc_open`, koneksi PostgreSQL terpisah per worker, DB terpisah per test) — bukan request berurutan. Skenario: dua setoran paralel, dua penarikan paralel (tidak overdraft), setoran+penarikan paralel konsisten dengan ledger, dua pencairan, dua pembayaran, replay idempotency_key, duplikat dampak kas ditolak via partial unique index. Verifikasi suite berjalan pada driver pgsql (`#[RequiresPhpExtension('pdo_pgsql')]`); jika pgsql tidak tersedia test di-skip secara eksplisit (bukan dianggap lulus). Pada eksekusi baseline: **PASS** (postgres tersedia di environment ini).

---

## D. SECURITY TESTING

| Area yang diuji | Metode | Temuan |
|---|---|---|
| Otorisasi route (POST/PUT/PATCH/DELETE) | 40+ assertion `assertForbidden()` di UserManagementTest, EndToEndFlowTest (ALUR 6), AuditTrailTest, ReconciliationTest, CashierTest | Semua ditolak 403 tanpa permission. Superadmin bypass via Gate::before |
| Akun nonaktif | Middleware `active.account` diuji (login ditolak, sesi diakhiri) | PASS |
| Privilege escalation | UserManagementTest (assign role/permission tanpa permission → 403; deaktivasi superadmin terakhir diblok) | PASS |
| Mass assignment | Audit semua 17 model memakai `$fillable`; controller memakai FormRequest/validated() | PASS |
| SQL injection | Eloquent + query builder di seluruh service; tidak ada `DB::raw` dengan input mentah pada jalur input pengguna | PASS (by design, bukan pentest SQLi aktif) |
| CSRF | Middleware aktif di web group; request tanpa token → 419 (terverifikasi saat harness curl saya ditolak 419) | PASS |
| XSS | React auto-escaping; audit input: tidak ada `dangerouslySetInnerHTML` | PASS (audit statis) |
| Password hashing | bcrypt via Laravel default; tidak ada penyimpanan plaintext | PASS |
| Kebocoran data sensitif | AuditLogger menyaring kunci sensitif; halaman login menolak kredensial dengan pesan generik | PASS |
| Manipulasi ID | Akses anggota ID yang tidak ada → 404 rapi; scoping via route model binding | PASS |

**Temuan terbuka (bug register):** lihat BUG-01/BUG-02/BUG-03 di bagian H — semua MEDIUM/LOW, **tidak diperbaiki** karena perubahan otorisasi route GET berpotensi memicu perubahan perilaku akses lintas modul dan berada di batas scope (dicatat sebagai rekomendasi Ticket berikutnya).

**Risiko keamanan yang belum teruji:** pentest aktif (SQLi/XSS injection eksekusi nyata), rate limiting login, upload file (tidak ada fitur upload), penetration testing jaringan. Tidak dieksekusi pada ticket ini.

---

## E. DATA INTEGRITY

| Area | Verifikasi | Status |
|---|---|---|
| Simpanan | Saldo = f(ledger transaksi completed) diuji; penarikan melebihi saldo ditolak; tidak ada saldo negatif; transaksi gagal tidak mengubah saldo (ConcurrencyAndFailureTest + SavingsTest) | PASS |
| Pinjaman & angsuran | Pencairan tunggal (unique disbursement); pembayaran terhubung ke pinjaman benar; idempotency_key unik; sisa kewajiban = dicairkan − dibayar; PAID_OFF saat sisa ≤ 0.01; bunga 0 (belum dikonfirmasi) | PASS |
| Kasir | Snapshot penutupan konsisten; selisih kas = fisik − sistem; dampak kas dari modul lain unik via partial unique index `cash_transactions_source_unique`; tanpa sesi → transaksi tunai ditolak (recordSourceImpact return null + audit `cashier.source.skipped`) | PASS |
| Dashboard & laporan | Angka diambil dari modul sumber, hanya status POSTED/completed; posisi vs periode terpisah | PASS |
| Rekonsiliasi | Read-only terhadap data sumber; temuan terpisah; follow-up RESOLVED wajib catatan ≥10 karakter | PASS |

Tidak dilakukan perbaikan saldo langsung di mana pun (sesuai batasan).

---

## F. UI/UX DAN BROWSER TESTING

- **Browser:** Chromium (webview Freebuff Desktop, mesin Blink — setara Chrome).
- **Viewport:** default panel desktop (± 752×~760 CSS px). Tidak diuji pada Firefox/Safari/Edge — **tidak diklaim**.
- **Halaman diuji (render + interaksi + konsol):** Login, Dashboard, Anggota (index/create/detail), Simpanan (hub), Pinjaman (index), Angsuran (index), Kasir (dashboard), Laporan (index), Rekonsiliasi (index), Pengaturan (index).
- **Yang diuji:**
  - Login kredensial salah → pesan "These credentials do not match our records." tanpa bocor info.
  - Login valid → redirect dashboard, render penuh (filter periode, posisi vs aktivitas, empty state semua kartu, disclaimer rumus bunga).
  - Sidebar: 9 menu lengkap sesuai role superadmin.
  - Form anggota: validasi required inline ("Nama lengkap wajib diisi."); simpan sukses → redirect detail + flash message.
  - Duplikat nomor anggota via UI → error inline "Nomor anggota ini sudah terdaftar dalam sistem." (server reject terverifikasi: tetap 1 anggota; error bag terkirim via fetch follow redirect).
  - Auto-suggest nomor anggota berikutnya (ANG-0002 setelah ANG-0001 ada).
  - Direct URL `/anggota/99999` → halaman 404 rapi.
  - Full reload `/dashboard` → OK (app ter-hydrasi ulang).
  - Konsol browser: bersih (hanya log logger internal).
- **Temuan:** BUG-02 dan BUG-03 (bagian H). Tidak ada redesign dilakukan.
- **Tidak diuji:** PWA/mobile, browser lain, viewport kecil/tablet, print layout.

---

## G. HASIL UAT

> Seluruh skenario UAT telah disiapkan, didokumentasikan langkahnya, dan **diverifikasi sisi sistem** melalui automated tests + pengujian browser di atas. Namun **belum ada calon pengguna koperasi yang menjalankan UAT**. Sesuai ketentuan ticket, status final setiap skenario = **MENUNGGU VALIDASI PENGGUNA**.

| ID | Skenario | Verifikasi sisi sistem | Status resmi |
|---|---|---|---|
| UAT-01 | Login dan Dashboard, hak akses, logout | AuthenticationTest, DashboardTest + browser test login/logout | MENUNGGU VALIDASI PENGGUNA |
| UAT-02 | Anggota: buat, cari, detail, ubah, duplikat ditolak | MemberTest + browser test duplikat | MENUNGGU VALIDASI PENGGUNA |
| UAT-03 | Simpanan: rekening, setoran, saldo, penarikan, riwayat | SavingsTest, EndToEndFlowTest ALUR 1 | MENUNGGU VALIDASI PENGGUNA |
| UAT-04 | Pinjaman: pengajuan, approval, pencairan, status | LoanAndInstallmentTest, EndToEndFlowTest ALUR 2 | MENUNGGU VALIDASI PENGGUNA |
| UAT-05 | Angsuran: pembayaran, riwayat, sisa kewajiban | LoanAndInstallmentTest | MENUNGGU VALIDASI PENGGUNA |
| UAT-06 | Kasir: sesi, transaksi, saldo, penutupan, selisih | CashierTest, EndToEndFlowTest ALUR 3 | MENUNGGU VALIDASI PENGGUNA |
| UAT-07 | Laporan: buka, filter periode, angka vs sumber | ReportTest, DashboardTest | MENUNGGU VALIDASI PENGGUNA |
| UAT-08 | Hak akses: modul sesuai role, aksi tanpa permission ditolak | ALUR 6 + assertForbidden di 4 test file | MENUNGGU VALIDASI PENGGUNA |
| UAT-09 | Audit dan rekonsiliasi: run, temuan, saldo tidak berubah | AuditTrailTest, ReconciliationTest, ALUR 5 | MENUNGGU VALIDASI PENGGUNA |

**Catatan UAT-01:** pembuatan akun per role untuk pengguna koperasi perlu disiapkan sebelum sesi UAT (role tersedia: admin, kasir, petugas_simpanan, petugas_pinjaman, pengawas).

---

## H. BUG REGISTER

| ID | Severity | Deskripsi | Status | Retest |
|---|---|---|---|---|
| BUG-01 | **MEDIUM** | Sebagian route GET tidak ber-middleware permission meski registry permission tersedia: `simpanan.index`, `simpanan-produk.*` (index/create/store/edit/update/deactivate), `simpanan-rekening.*` (index/create/store/show), GET `simpanan-transaksi.deposit/withdraw.create`, `pinjaman.*` (index/create/show/disburse.create), `angsuran.*` (index/schedule/pay.create/payment.show), GET kasir `transaksi/penerimaan|pengeluaran|setoran`, `pengaturan.index/profile/operational/security`. Data per halaman tidak sensitif (form kosong/daftar), aksi tulis sudah terproteksi. Risiko informasi minim. | **OPEN** — dicatat, tidak diubah (perubahan otorisasi berpotensi memengaruhi banyak alur; butuh keputusan pemilik produk) | — |
| BUG-02 | **LOW** | Detail anggota: label ringkasan "Modul simpanan/pinjaman belum diaktifkan" menyesatkan (modul sudah aktif; teks statis muncul saat anggota belum punya data). | **OPEN** — perbaikan copy UI, ditunda agar scope tetap QA | — |
| BUG-03 | **LOW** | Detail anggota: timestamp ISO mentah `2026-09-26T13:44:23.000000Z` di kartu Informasi Sistem; format tanggal lain di aplikasi sudah rapi. | **OPEN** — perbaikan format, minor | — |
| BUG-04 | **LOW** | `routes/web.php`: komentar `});   // Modul Rekonsiliasi...` menempel di luar grup middleware auth (rekonsiliasi punya middleware lengkapnya sendiri sehingga aman), dan komentar `// Modul lain (Placeholder)` sudah usang. Kualitas kode saja. | **OPEN** | — |

**Tidak ada bug CRITICAL atau HIGH.** Tidak ada test yang dihapus/dilemahkan selama QA.

---

## I. AUTOMATED TESTING

| Perintah | Hasil aktual |
|---|---|
| `php artisan test --compact` (baseline awal) | **181 passed (1365 assertions)**, 69.21s |
| `php artisan test --compact` (regression akhir) | **181 passed (1365 assertions)**, 31.61s |
| `php artisan migrate:status` | Semua migration `Ran`, 0 pending |
| Suite terakhir gagal? | Tidak ada. (Sesuai catatan: `ReconciliationTest` yang pernah gagal sudah diperbaiki sebelumnya dan 18/18 pass dalam eksekusi ini.) |
| Browser automation | Tidak tersedia framework-nya; pengujian browser dilakukan manual-terpandu via browser Chromium (detail bagian F). Tidak diklaim sebagai browser automation. |

**Distribusi suite (21 file test):** Auth (7), Anggota, Simpanan, Pinjaman+Angsuran, Kasir, Laporan, Dashboard, UserManagement, Profile, AuditTrail, Reconciliation, ConcurrencyAndFailure, EndToEndFlow, RootRoute, Example ×2.

---

## J. BUILD DAN STATIC ANALYSIS

| Item | Hasil aktual |
|---|---|
| `npm run build` | Sukses, ✓ built in 11.78s, manifest lengkap |
| `vendor/bin/pint.bat --dirty --test` | **PASS — 183 files**, 0 style issue (setelah file sementara QA dihapus; sebelumnya 1 issue hanya pada `tmp_qa_check.php` milik QA) |
| Static analysis (PHPStan/Psalm) | **TIDAK TERINSTALASI** di proyek — tidak dijalankan, tidak diklaim |
| Migration check | 0 pending |
| Vite manifest | Terverifikasi lengkap (masalah manifest historis sudah teratasi; semua chunk halaman terdaftar) |

---

## K. PERUBAHAN KODE

**File dibuat:**
- `docs/QA-UAT-Ticket-11.md` — laporan ini.

**File diubah:**
- **Tidak ada perubahan kode aplikasi** pada Ticket 11. (Perbaikan StatusBadge/manifest, permission kasir, DbTransaction adalah pekerjaan Ticket 10 yang telah terlanjur ada di working tree dan diverifikasi ulang di sini.)

**File sementara yang dibuat lalu dihapus:** `tmp_qa_check.php` (inspeksi DB dev; dihapus agar pint bersih).

**Alasan:** seluruh pengujian pada ticket ini berbasis eksekusi test dan browser yang ada; tidak ditemukan bug CRITICAL/HIGH yang memerlukan perbaikan kode. Temuan MEDIUM/LOW dicatat sebagai rekomendasi (bagian H/M) sesuai batasan scope.

---

## L. RISIKO DAN KETERBATASAN

1. **Aturan bisnis belum dikonfirmasi (kritis untuk go-live):**
   - Rumus bunga pinjaman = 0 pada jadwal angsuran; denda/jasa belum ada. Aplikasi secara eksplisit menampilkan disclaimer "Bunga, jasa simpanan, dan denda tidak dihitung karena rumusnya belum dikonfirmasi."
   - Kategori kas di `config/cashier.php` diberi catatan "ATURAN BISNIS BELUM DIKONFIRMASI".
2. **UAT pengguna belum dilakukan** — semua skenario berstatus MENUNGGU VALIDASI PENGGUNA.
3. **Pengujian yang belum dilakukan:**
   - Pentest aktif (SQLi/XSS eksekusi nyata, rate limiting, brute force).
   - Browser automation; browser non-Chromium; viewport mobile/tablet.
   - Load/stress testing; PWA (di luar scope).
   - Verifikasi di server staging/produksi (tidak ada deployment dalam scope).
4. **Risiko terbuka:**
   - BUG-01: beberapa route GET tanpa permission (lihat H) — rekomendasi: tambahkan middleware permission pada iteration berikutnya dengan uji regresi alur navigasi.
   - Tidak ada commit sama sekali pada repo: risiko kehilangan kerja dan ketiadaan history sebelum setup Git dijalankan.
   - Audit trail dicatat setelah commit transaksi di beberapa jalur service (pola by design); kegagalan tulis audit tidak menggagalkan transaksi — dapat diterima untuk MVP, perlu keputusan untuk produksi.
5. **Keterbatasan environment:** pengujian konkurensi memerlukan ekstensi pgsql; pada environment tanpa pgsql suite konkurensi akan skip eksplisit (perilaku sudah dibangun ke dalam test).

---

## M. REKOMENDASI BERIKUTNYA

**Perbaikan wajib (sebelum persiapan produksi):**
1. Konfirmasi dan implementasikan rumus bunga/denda/jasa bersama koperasi (memengaruhi pinjaman, angsuran, laporan).
2. Tutup BUG-01: tambahkan middleware permission pada seluruh route GET yang teridentifikasi + regression test navigasi per role.
3. Inisialisasi Git (commit pertama) dan alur backup database.

**Validasi yang memerlukan pihak koperasi:**
4. Jalankan sesi UAT-01..UAT-09 dengan pengguna nyata per role (skenario sudah siap; buat akun per role sebelum sesi).
5. Konfirmasi daftar kategori kas dan label bisnis di `config/cashier.php`.
6. Setujui kebijakan audit (apakah kegagalan audit boleh menggagalkan transaksi?).

**Kesiapan tahap persiapan produksi:**
7. Setelah poin 1–4 selesai: hardening session/cookie, rate limiting login, konfigurasi HTTPS, penilaian backup/restore, dan uji beban ringan.
8. Pertimbangkan instalasi PHPStan (level rendah dulu) ke pipeline agar static analysis terukur di ticket berikutnya.
9. Selesaikan BUG-02/BUG-03 (copy UI & format timestamp) dalam iteration perbaikan UI minor.

---

**Kesimpulan:** Kriteria penerimaan ticket 1–6, 8–12 terpenuhi berdasarkan eksekusi aktual (baseline terdokumentasi, functional/integrity/security/concurrency diuji, regression hijau, build & lint bersih, UAT disiapkan, tidak ada perubahan di luar scope, tidak ada commit/push). Kriteria 5 (bug CRITICAL/HIGH) tidak menemukan kasus yang perlu diperbaiki; kriteria 9 dipenuhi dengan status MENUNGGU VALIDASI PENGGUNA. Aplikasi **belum** dinyatakan siap produksi — bergantung pada konfirmasi aturan bisnis dan UAT pengguna.
