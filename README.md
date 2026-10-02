# Koperasi APP

Sistem Informasi Manajemen Koperasi Simpan Pinjam berbasis web dan Mobile Progressive Web App (PWA).

## 1. Tech Stack

- **Backend**: Laravel 12 (PHP 8.5)
- **Frontend**: React 18 + Inertia.js
- **Styling**: Tailwind CSS
- **Database**: PostgreSQL 18
- **Build Tool**: Vite 6
- **Version Control**: Git (Branch: `staging`)

## 2. Persyaratan Environment

Sebelum menjalankan project di lingkungan lokal, pastikan perangkat Anda telah terpasang:

- **PHP**: >= 8.3 (Extension `pdo_pgsql` dan `pgsql` aktif)
- **Composer**: >= 2.x
- **Node.js**: >= 20.x
- **npm**: >= 10.x
- **PostgreSQL**: Server aktif di `127.0.0.1:5432`
- **Git**: Versi terbaru

## 3. Langkah Instalasi Lokal

### A. Clone & Setup Directory
```bash
git clone <repository-url>
cd ksp-bima-sakti
git checkout staging
```

### B. Instalasi Dependency Backend & Frontend
```bash
composer install
npm install
```

### C. Konfigurasi Environment & Database
1. Salin `.env.example` ke `.env`:
   ```bash
   cp .env.example .env
   ```
2. Pastikan konfigurasi database PostgreSQL di file `.env` sudah sesuai:
   ```env
   DB_CONNECTION=pgsql
   DB_HOST=127.0.0.1
   DB_PORT=5432
   DB_DATABASE=ksp_bima_sakti
   DB_USERNAME=postgres
   DB_PASSWORD=your_postgres_password
   ```
3. Generate Key Aplikasi:
   ```bash
   php artisan key:generate
   ```

### D. Jalankan Database Migration
Pastikan database `ksp_bima_sakti` telah dibuat di PostgreSQL, kemudian jalankan:
```bash
php artisan migrate
```

### E. Build Frontend Assets
Untuk mengompilasi asset React + Inertia.js:
```bash
npm run build
```

## 4. Cara Menjalankan Aplikasi

Jalankan dua terminal terpisah untuk server Laravel dan Vite Dev Server:

- **Terminal 1 (Laravel Server)**:
  ```bash
  php artisan serve
  ```
  Akses di browser: `http://localhost:8000`

- **Terminal 2 (Vite Development Server)**:
  ```bash
  npm run dev
  ```

## 5. Menjalankan Pengujian (Testing)

Untuk menjalankan seluruh suite pengujian otomatis (Laravel Feature & Unit Tests):
```bash
php artisan test
```

## 6. Struktur Autentikasi Dasar

Fase Bootstrap menyediakan fitur autentikasi bawaan Inertia React Starter Kit:
- **Register**: `http://localhost:8000/register`
- **Login**: `http://localhost:8000/login`
- **Dashboard**: `http://localhost:8000/dashboard` (Memerlukan login)
- **Profile**: `http://localhost:8000/profile` (Edit profil, ubah password, hapus akun)
