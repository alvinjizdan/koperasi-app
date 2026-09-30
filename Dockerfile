# =====================================================================
# KSP Bima Sakti Mandiri — Docker image (PHP-FPM + Nginx stack)
# Stage 1 (node): install dependency frontend + build Vite (aset Inertia).
# Stage 2 (app) : PHP-FPM + Composer dependency production + aset build.
# Tidak ada secret di dalam file ini; semua kredensial via environment.
# =====================================================================

# ---------- Stage 1: build frontend (React + Inertia via Vite) ----------
FROM node:22-alpine AS node

WORKDIR /app

# Dependency dulu agar layer ter-cache
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

# Source yang dibutuhkan Vite saja (bukan seluruh repo)
COPY resources/ resources/
COPY vite.config.js tailwind.config.js postcss.config.js ./

RUN npm run build

# ---------- Stage 2: aplikasi Laravel (PHP-FPM) ----------
# PHP 8.4 memenuhi composer requirement aktual proyek: "php": "^8.3"
FROM php:8.4-fpm-alpine AS app

# Dependency sistem: icu (intl), libpng/jpeg/freetype (gd), libpq (pgsql)
RUN apk add --no-cache icu-dev libpng-dev libjpeg-turbo-dev freetype-dev postgresql-dev

# Ekstensi PHP sesuai kebutuhan aktual: pdo_pgsql (PostgreSQL),
# intl + bcmath (paket Laravel/bisnis), gd (spare), opcache (production)
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo pdo_pgsql pgsql intl bcmath gd opcache

# Composer dari image resmi
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependency PHP dulu (cache layer), tanpa dev packages
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --no-scripts

# Source aplikasi (exclude via .dockerignore: .git, tests, docs, node_modules, vendor, .env*)
COPY . .

# Aset frontend hasil build dari stage node (public/build berisi manifest + assets)
COPY --from=node /app/public/build public/build

# Hapus skrip PHP development yang tidak dipakai runtime (bukan file standar aplikasi)
RUN rm -f public/hot

# Composer scripts post-autoload (package:discover) butuh aplikasi utuh
RUN composer dump-autoload --optimize --no-dev --no-interaction

# Permission storage & bootstrap/cache untuk www-data (Laravel wajib bisa menulis)
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

# Konfigurasi PHP production + entrypoint (tunggu DB, migrasi terkontrol, cache)
COPY docker/php/prod.ini /usr/local/etc/php/conf.d/zz-prod.ini
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
# Guard line-ending Windows (CRLF membuat sh gagal di alpine)
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh \
    && chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
