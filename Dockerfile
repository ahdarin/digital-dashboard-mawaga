# syntax=docker/dockerfile:1
#
# Satu image dipakai untuk SEMUA service Railway (web/worker/scheduler/all) -
# perannya ditentukan environment variable PROCESS_ROLE, dibaca oleh
# docker/entrypoint.sh saat container start. Ini yang membuat pindah dari
# setup 2-service ke 4-service nanti tidak perlu build ulang logic apa pun,
# cukup ubah PROCESS_ROLE per service di Railway.

# =========================================================================
# Stage 1: build asset frontend (Vite + Tailwind)
# =========================================================================
FROM node:20-bookworm-slim AS frontend

WORKDIR /app
# Catatan: package-lock.json TIDAK ada di repo ini (dicek: tidak tracked di
# git, tidak ada di disk) - jadi `npm ci` (yang mewajibkan lockfile) tidak
# bisa dipakai di sini. `npm install` dipakai sebagai gantinya.
COPY package.json ./
RUN npm install
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm run build

# =========================================================================
# Stage 2: install dependency PHP (composer)
# =========================================================================
FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative

# =========================================================================
# Stage 3: runtime - PHP-FPM + Nginx + Supervisor + Python (Delay Risk ML)
# =========================================================================
FROM php:8.3-fpm-bookworm AS runtime

# Ekstensi PHP yang benar-benar dipakai dependency terpasang (diverifikasi
# dari composer.lock: laravel/framework, dompdf/dompdf, phpoffice/phpspreadsheet
# lewat maatwebsite/excel) - bukan daftar tebakan.
RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
        gettext-base \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libzip-dev \
        libicu-dev \
        libxml2-dev \
        libcurl4-openssl-dev \
        python3 \
        python3-venv \
        python3-pip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        mbstring \
        bcmath \
        gd \
        zip \
        intl \
        exif \
        curl \
        pcntl \
        opcache \
    && rm -rf /var/lib/apt/lists/*
# Catatan: paket -dev (libpng-dev, libcurl4-openssl-dev, dst) SENGAJA tidak
# di-purge setelah build. `apt-get purge --auto-remove` bisa ikut menghapus
# shared library runtime (libcurl4, libzip4, libicu72, dst) yang justru
# dibutuhkan ext gd/curl/zip/intl saat container jalan, bukan cuma saat
# build - risiko itu tidak sepadan dengan penghematan beberapa puluh MB.

# Virtualenv Python KHUSUS untuk predict_batch.py (Delay Risk). scikit-learn
# WAJIB persis 1.6.1 sesuai storage/ai/delay_risk/requirements.txt - model
# .pkl gagal di-load kalau versinya beda (lihat catatan di file itu).
COPY storage/ai/delay_risk/requirements.txt /tmp/delay-risk-requirements.txt
RUN python3 -m venv /opt/venv \
    && /opt/venv/bin/pip install --no-cache-dir --upgrade pip \
    && /opt/venv/bin/pip install --no-cache-dir -r /tmp/delay-risk-requirements.txt \
    && rm /tmp/delay-risk-requirements.txt

# DelayRiskPredictionService::callPredictScript() membaca PYTHON_BIN dari
# env - arahkan ke python di venv, bukan python3 sistem yang tidak punya
# scikit-learn/pandas/joblib terpasang.
ENV PYTHON_BIN=/opt/venv/bin/python3

RUN docker-php-ext-enable opcache
COPY docker/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=frontend /app/public/build ./public/build

# docker/ sudah diambil isinya lewat COPY eksplisit di atas & di bawah -
# dihapus dari pohon aplikasi supaya tidak ada duplikat file config di
# dalam image.
RUN rm -rf docker \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

COPY docker/nginx.conf.template /etc/nginx/templates/default.conf.template
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && rm -f /etc/nginx/sites-enabled/default

# Railway menyuntikkan $PORT saat runtime - nilai di sini cuma dokumentasi,
# entrypoint yang benar-benar membaca $PORT lewat envsubst.
EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
