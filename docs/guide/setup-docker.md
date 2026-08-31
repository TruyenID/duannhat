---
title: Setup with Docker
category: guide
tags: [setup, installation, docker, development]
summary: Spin up the TempoFast dev stack (Laravel API + MySQL + MinIO + Mailpit) with a single `docker compose up`. ~260 MB RAM, no host PHP/MySQL needed. phpMyAdmin opt-in via profile.
related:
  - ../backend/docs/guide/setup-local.md
---

# Setup with Docker

The fastest way to run TempoFast for the first time — no PHP, MySQL, Composer, or anything else on the host. Just Docker.

The default stack uses **~260 MB RAM** (mysql 116 + app 50 + minio 80 + mailpit 13). MySQL is tuned for dev (innodb buffer 64M, perf_schema OFF). phpMyAdmin lives in a separate profile to save another 40 MB for anyone who doesn't need a database GUI.

## Prerequisites

- **Docker Desktop** (or OrbStack on macOS) — any version with Compose v2 support (every recent release qualifies)
- ~3 GB of disk for images and data volumes

No PHP, no MySQL, no `.env` file needed — Docker is fully configured via `docker-compose.yml`.

> **⚠️ Windows users**: where the repo lives on disk **matters a lot** for performance. Read [Performance on Windows](#performance-on-windows) below before cloning.

## Steps

### 1. Clone the repo

```sh
git clone https://github.com/godx-jp/godx-tempo.git
cd godx-tempo
```

This is a **monorepo** — one plain clone gets every app. No submodule step:
`backend/` (Laravel), `web/{admin,customer,pos}` (Next.js / Vite),
`app/{tms,kiosk,kds,handy}` (Expo / Vite) and `workstation/` (Go + Wails) are all
tracked directly in this repo.

### 2. Start the stack

```sh
docker compose up -d
```

The first run will:

1. Build the image (~2 minutes)
2. Install composer dependencies into the `app-vendor` named volume (~1-2 minutes)
3. Run migrations (`php artisan migrate --force --isolated`)
4. **Seed the database** (demo catalog + dev accounts — first boot only, gated by the `storage/framework/.seeded` marker)
5. Start the Laravel API at `http://localhost:5400`
6. Create the marker file `storage/framework/.initialized` so steps 1-4 are skipped on later restarts

Subsequent `docker compose up -d` runs take **~1 second** to be ready (the marker files skip all heavy ops; only the idempotent migrate + `php -S` startup run).

> **Zero-config SSO**: the compose file ships the shared dev SSO credentials (instance `tempo-dev` on `dev-console.godx.jp`), so after `up -d` you can immediately sign in to admin-web with `info@famgia.com` / `@Famgia2026.` — no `.env`, no console-admin access, no manual seeding. See [SSO Authentication](./sso-authentication.md).
>
> Both marker files live in the `app-storage-framework` named volume, which is removed together with `mysql-data` on `docker compose down -v` — so a volume wipe always re-migrates **and** re-seeds on the next `up`.

### 3. Verify

```sh
curl http://localhost:5400/api/v1/auth/sso/user
# → 401 Unauthenticated  ✓ (endpoint reachable)
```

Or open one of these UIs:

| Service | URL | Login | Default? |
|---|---|---|---|
| **Dev Portal** (hub linking to every UI) | http://localhost:5480 | — | ✓ |
| Laravel API | http://localhost:5400 | — | ✓ |
| Mailpit (mail UI) | http://localhost:5482 | — | ✓ |
| MinIO Console | http://localhost:5491 | `minio` / `minio123` | ✓ |
| Next.js admin-web | http://localhost:5430 | — | **profile `admin-web`** |
| phpMyAdmin | http://localhost:5481 | auto-login | **profile `tools`** |

> **All ports use the `54xx` range** to avoid clashing with commonly used local services (3000, 8000, 8080…).
>
> The MinIO S3 API and Mailpit SMTP are **not exposed to the host** — containers reach each other via internal hostnames (`mysql`, `minio`, `mailpit`).
>
> phpMyAdmin lives in the `tools` compose profile → NOT started by default. Enable with:
> ```sh
> docker compose --profile tools up -d
> ```

## Admin web (Next.js)

**Recommended: run natively on the host with `npm run dev`**, not through Docker:

```sh
cd admin-web
npm install
npm run dev    # → http://localhost:3000
```

Why admin-web is NOT in Docker by default:

- Next.js HMR over a Windows/WSL2 bind mount needs `WATCHPACK_POLLING=true` → CPU thrash, hot-reload lags 1-3s per save.
- Installing Node on the host is far easier than installing PHP/MySQL/Composer (`nvm install 22 && npm i` and you're done).
- VS Code / Cursor run the TypeScript LSP against the same `node_modules` → "go to definition", refactors, and test runners all stay smooth.
- Native browser DevTools / source maps without a proxy → faster debugging.

**When to use the `admin-web` profile?** When you don't want Node on the host (Windows newcomers, QA demos, CI smoke). Enable:

```sh
docker compose --profile admin-web up -d
# or together with the backend:
docker compose --profile admin-web up -d
```

The `tempo-admin-web` service builds its image on first run (~1-2 minutes), seeds `node_modules` into a named volume, then runs `npm run dev` at `http://localhost:5430`. Later restarts take ~3-5s thanks to the `node_modules/.installed` marker file.

Adjust `NEXT_PUBLIC_API_URL` (default `http://localhost:5400`) in `docker-compose.yml` if the backend is not on the default port.

## First-time MinIO bucket setup

The `dxs-product` bucket must be created manually once:

1. Open http://localhost:5491
2. Login: `minio` / `minio123`
3. Buckets → **Create Bucket** → name: `dxs-product` → **Create**

## Common commands

```sh
# Run in the background (default — no phpMyAdmin)
docker compose up -d

# Run with phpMyAdmin
docker compose --profile tools up -d

# Tail logs in real time
docker compose logs -f
docker compose logs -f app        # Laravel only

# Shell into the app container
docker compose exec app bash

# Run artisan
docker compose exec app php artisan migrate
docker compose exec app php artisan tinker
docker compose exec app php artisan test

# Fast app restart (warm restart, ~1s thanks to the marker file)
docker compose restart app

# Stop (graceful — the exec'd php receives SIGTERM directly, ~0.2s)
docker compose down

# Stop + delete volumes (loses DB, MinIO data, vendor cache, marker file → next up is a cold start)
docker compose down -v
```

## Running tests

```sh
docker compose exec app php artisan test
```

Tests use in-memory SQLite (configured in `phpunit.xml`) and do **not** touch Docker's MySQL — so you can run tests at any time without risking dev data.

## Performance on Windows

On macOS/Linux everything is fast by default. On Windows + WSL2 there are 2 things to get right:

### 1. Put the repo INSIDE the WSL2 filesystem, not on `C:\`

Containers read files through a bind mount. If the repo sits on `C:\` (NTFS), Docker pushes every file through the **9P bridge between Windows ↔ WSL2** → 10–100× slower. If the repo lives inside WSL2 (`/home/<user>/...` on ext4), access is as fast as on Mac/Linux.

**How to check**: open a terminal in the project and run `pwd`:

| Output | Where you are | Speed |
|---|---|---|
| `C:\Users\...` or `/mnt/c/...` | NTFS via the 9P bridge | 🐌 |
| `/home/<user>/...` | WSL2 ext4 | 🚀 |

**How to fix**: open Ubuntu from the Start Menu → `cd ~ && git clone <repo>` → open VS Code with `code .` from that terminal (the bottom-left corner of VS Code must show `WSL: Ubuntu`).

### 2. CLI OPcache is already enabled

Compose uses `php -S` as the CLI server. By default OPcache is **OFF** in CLI mode → every request re-parses 500+ PHP files from disk → 5–15 seconds per request even on the WSL2 filesystem.

`docker/php/opcache.ini` enables `opcache.enable_cli=1` + file_cache + a large realpath cache → cold start ~3-5s, warm requests **<200ms** on Windows. The file is mounted straight into the container, so **no image rebuild** is needed to tweak it:

```sh
vim docker/php/opcache.ini
docker compose restart app
```

Quick OPcache sanity check: create a temporary `public/_opcache.php` containing `<?php print_r(opcache_get_status(false));`, hit it a few times, and confirm `num_cached_scripts > 500` and `hit_rate > 95%`. Delete the file afterwards.

## Notes

- The host's `.env` file is **never touched by Docker**. Docker reads env directly from `docker-compose.yml`. If a `.env` exists at the root, it belongs to the native Herd setup (see [Setup without Docker](../../backend/docs/guide/setup-local.md)).
- The `APP_KEY` in compose is a shared dev key — **never** use it in production.
- The `mysql-data` and `minio-data` volumes persist across `docker compose up/down`. They are only lost on `docker compose down -v`.
- Every service has `restart: unless-stopped` → dead containers come back on their own. No cron/supervisor needed.
- The app has a healthcheck (`/up` endpoint, 10s interval) → `docker compose ps` shows `(healthy)` once it's ready to serve.
- MySQL is lightly tuned for dev: `innodb-buffer-pool-size=64M`, `performance-schema=OFF`, `max-connections=64`. Plenty for migrations + the full test suite, not suitable for benchmarks.

## Troubleshooting

**Port 5400/5480/5481/5482/5491 already in use:**
Edit `docker-compose.yml` → change the host port on the left (e.g. `5400:8000` → `5500:8000`).

**The `app` container reports connection refused to `mysql`:**
MySQL is still initializing on first run (~10s). Compose already declares `depends_on: mysql: condition: service_healthy`, so this is rare — if it still happens, `docker compose logs -f mysql`, wait for `ready for connections`, then `docker compose restart app`.

**PHP requests take 5–15 seconds on Windows:**
- Check whether the repo sits on `C:\` (see [Performance on Windows](#performance-on-windows))
- Check OPcache is working: `docker compose exec app php -r 'echo ini_get("opcache.enable_cli");'` → must print `1`
- Check `docker/php/opcache.ini` is actually mounted: `docker compose exec app cat /usr/local/etc/php/conf.d/opcache.ini`

**Code doesn't hot-reload after edits:**
OPcache revalidates with `validate_timestamps=1` + `revalidate_freq=0` → every file change is picked up immediately. If not, hard-reset the cache: `docker compose exec app php -r 'opcache_reset();'`. If that still doesn't help, the bind mount is delayed (Windows + `C:\`).

**Stack feels heavy / machine runs hot:**
Check `docker stats` → total RAM should be ~260 MB. If it's much higher you may have started `--profile tools` by accident or have stale containers. Reset with `docker compose down --remove-orphans && docker compose up -d`.

**Want a clean DB reset:**
```sh
docker compose down -v
docker compose up -d
```

## Next steps

- Read [Architecture](../../backend/docs/reference/architecture.md) to understand the project structure
- See [SSO Authentication](./sso-authentication.md) for the auth flow
