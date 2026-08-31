---
title: Setup without Docker (Laravel Herd)
category: guide
tags: [setup, installation, herd, native, development]
summary: Run TempoFast natively on macOS with Laravel Herd. Faster than Docker for daily dev, but requires installing PHP 8.4 and MySQL on the host.
related:
  - guide/setup-docker.md
---

# Setup without Docker (Laravel Herd)

Chạy TempoFast native trên macOS với Laravel Herd. Nhanh hơn Docker cho dev hằng ngày (xartisan/test chạy tức thì, không có overhead container), nhưng cần cài PHP 8.4 + MySQL trên host.

> **Linux/Windows users:** Herd hiện chỉ hỗ trợ macOS và Windows. Trên Linux, dùng [Setup with Docker](./setup-docker.md) hoặc tự cài PHP 8.4 + nginx + mysql thủ công (không có hướng dẫn ở đây).

## Prerequisites

- **macOS** (Sonoma trở lên khuyến nghị)
- **[Laravel Herd](https://herd.laravel.com)** — cài bản free là đủ; bao gồm PHP, nginx, dnsmasq
  - Cần PHP **8.4** active (`herd php:list` để check)
- **MySQL 8.0+** — tự cài qua Homebrew hoặc dùng [DBngin](https://dbngin.com)
- **Composer 2.x** — Herd đã ship sẵn

```sh
brew install mysql@8.0
brew services start mysql@8.0
```

## Steps

### 1. Clone repo

```sh
git clone https://github.com/godx-jp/godx-tempo.git ~/Herd/dxs-product
cd ~/Herd/dxs-product
```

> **Quan trọng:** Clone vào thư mục **bên trong** `~/Herd/` (Herd parking dir). Herd auto-detect mọi project trong đây và tạo domain `<dirname>.test`. Vì folder tên `dxs-product` nên domain sẽ là `https://dxs-product.test`.

### 2. Install PHP dependencies

```sh
composer install
```

### 3. Create `.env`

```sh
cp .env.example .env
php artisan key:generate
```

Sửa các giá trị sau trong `.env` cho khớp local MySQL:

```env
APP_NAME=TempoFast
APP_URL=https://dxs-product.test

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=dxs_product
DB_USERNAME=root
DB_PASSWORD=

# SSO (bật console mode nếu có upstream IDP, không thì dùng standalone)
OMNIFY_AUTH_MODE=standalone
```

### 4. Create database

```sh
mysql -u root -e "CREATE DATABASE dxs_product CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### 5. Run migrations

```sh
php artisan migrate
```

### 6. Link storage

```sh
php artisan storage:link
```

### 7. Secure HTTPS (optional but recommended)

```sh
herd secure dxs-product
```

Herd cấp TLS cert tự ký cho `dxs-product.test`.

### 8. Verify

```sh
curl -k https://dxs-product.test/api/v1/auth/sso/user
# → 401 Unauthenticated  ✓ (endpoint reachable)
```

Hoặc mở `https://dxs-product.test` trong browser — sẽ trả 404 (vì project là API-only, không có route `/`).

## Running tests

```sh
php artisan test --compact
```

Tests dùng SQLite in-memory (cấu hình ở `phpunit.xml`), không đụng MySQL của bạn — chạy tự do bất cứ lúc nào.

Filter một file/test:

```sh
php artisan test --compact --filter=CategoryControllerContractTest
```

## Common commands

```sh
# Tail logs
herd php logs              # PHP errors
php artisan pail           # Laravel logs

# Tinker
php artisan tinker

# Reset DB sạch
php artisan migrate:fresh

# Pint (code formatter)
vendor/bin/pint --dirty

# Omnify codegen
npx omnify generate
npx omnify reset -y         # Wipe & regen
```

## Why Herd, not Sail/Valet?

- **Herd vs Valet:** Herd is the modern successor to Valet; bundles PHP versions, nginx, dnsmasq into a single GUI app. Less manual config.
- **Herd vs Sail:** Sail is a Docker wrapper — works but slower (volume mount overhead on macOS). Herd runs PHP natively → instant artisan/test.

## Mail / S3 / Queue (optional services)

Native setup không có sẵn Mailpit, MinIO, Redis. Có 3 lựa chọn:

1. **Dùng Docker chỉ cho infra services:** chạy `docker compose up -d mysql mailpit minio` (mở port ra host), Laravel kết nối qua `127.0.0.1:5482` etc. Phối hợp giữa Docker và Herd.
2. **Cấu hình mail thật:** set `MAIL_MAILER=smtp` + Mailtrap/Mailgun trong `.env`.
3. **File-based fallback** (đủ cho dev):
   ```env
   MAIL_MAILER=log         # log mail vào storage/logs/laravel.log
   FILESYSTEM_DISK=local   # storage local thay vì S3
   QUEUE_CONNECTION=sync   # job chạy đồng bộ, không cần worker
   ```

## Troubleshooting

**`https://dxs-product.test` báo 502 Bad Gateway:**
PHP version sai. Run `herd php:list` → đảm bảo `8.4` đang active. Nếu không: `herd php:use 8.4`.

**`ConnectionRefusedException` khi chạy migrate:**
MySQL chưa chạy. `brew services start mysql@8.0` hoặc start qua DBngin.

**`Symbolic link from "public/storage" to "../storage/app/public" already exists`:**
Đã có symlink rồi, bỏ qua. Hoặc xóa và tạo lại: `rm public/storage && php artisan storage:link`.

**Vite manifest error:**
Project là API-only, không có Vite assets. Lỗi này không nên xảy ra; nếu có, check route trỏ tới Blade view nào đó (sai — phải xoá).

## Next steps

- Đọc [Architecture](../reference/architecture.md) để hiểu project structure
- Xem [SSO Authentication](./sso-authentication.md) cho auth flow
- Xem [Setup with Docker](./setup-docker.md) nếu muốn chuyển qua Docker
