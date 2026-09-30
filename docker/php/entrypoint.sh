#!/bin/sh
# =====================================================================
# Entrypoint container app (PHP-FPM) — KSP Bima Sakti Mandiri
# - Tunggu PostgreSQL siap (bukan hanya container up)
# - Migrasi TIDAK pernah otomatis (RUN_MIGRATIONS=false default; terkontrol)
# - Cache config/route/view (OPTIMIZE_CACHE=true default) bila APP_KEY ada
# - APP_URL bisa diarahkan ke URL ngrok tanpa rebuild image
# =====================================================================
set -e

cd /var/www/html

# Sinkronkan root dokumen nginx (volume bersama) dengan public/ image:
# index.php + aset build Vite. Idempoten; nginx memount volume ini read-only.
mkdir -p /var/nginx-app/public
cp -a public/. /var/nginx-app/public/
echo "[entrypoint] Root dokumen nginx tersinkron (/var/nginx-app/public)."

echo "[entrypoint] Menunggu PostgreSQL di ${DB_HOST:-db}:${DB_PORT:-5432} ..."
i=0
until php -r 'try { new PDO(sprintf("pgsql:host=%s;port=%s;dbname=%s", getenv("DB_HOST") ?: "db", getenv("DB_PORT") ?: "5432", getenv("DB_DATABASE")), getenv("DB_USERNAME"), getenv("DB_PASSWORD"), [PDO::ATTR_TIMEOUT => 2]); echo "ok"; } catch (Throwable $e) { exit(1); }' >/dev/null 2>&1; do
    i=$((i+1))
    if [ "$i" -ge 60 ]; then
        echo "[entrypoint] PostgreSQL tidak merespons setelah 60 percobaan — keluar." >&2
        exit 1
    fi
    sleep 2
done
echo "[entrypoint] PostgreSQL siap."

# Migrasi HANYA bila diminta secara eksplisit (terkontrol, tidak per request)
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    echo "[entrypoint] RUN_MIGRATIONS=true — menjalankan migrate --force ..."
    php artisan migrate --force
    echo "[entrypoint] Selesai migrate. (Set RUN_MIGRATIONS=false lagi setelah ini.)"
fi

# Cache framework (aman untuk production; butuh APP_KEY valid)
if [ "${OPTIMIZE_CACHE:-true}" = "true" ]; then
    echo "[entrypoint] Membangun cache config/route/view ..."
    php artisan config:cache || echo "[entrypoint] config:cache gagal (APP_KEY kosong?)"
    php artisan route:cache || true
    php artisan view:cache || true
fi

# Izin storage (volume bisa membuat owner baru)
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

echo "[entrypoint] Menjalankan: $*"
exec "$@"
