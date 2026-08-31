FROM php:8.4-cli

RUN apt-get update && apt-get install -y \
        git unzip libzip-dev libpng-dev libonig-dev libxml2-dev libicu-dev \
    && docker-php-ext-install pdo_mysql mbstring zip exif pcntl bcmath gd intl opcache \
    && rm -rf /var/lib/apt/lists/*

# OPcache config được mount từ host (docker/php/opcache.ini) qua volume trong
# docker-compose.yml. Tách ra để chỉnh không phải rebuild image — chỉ cần
# `docker compose up -d` lại là xong.
RUN mkdir -p /tmp/opcache && chmod 777 /tmp/opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Pre-install vendor vào image để container start không phải cài lại từ đầu.
# Vendor sẽ nằm trong named volume "app-vendor" ở compose, lần đầu start sẽ
# được seed từ image này (nhanh) thay vì chạy composer install qua bind mount Windows (chậm).
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader --no-dev || true

EXPOSE 8000
