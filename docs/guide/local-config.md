---
title: Local config and the production boundary
category: guide
tags: [env, local-config, feature-flag, sso-bypass, ports]
summary: "Every dev-only flag, env file, port and login bypass in one place, plus the hard rules that keep local configuration from ever reaching production."
related: []
---

# Local configuration — and the hard boundary with production

This document collects **everything** that exists only for a developer machine or
an internal box: feature flags, env files, ports, the login bypass, image
rewrites. It has a single purpose: to make sure local configuration can **never**
reach production.

> **The unbreakable rule**
>
> Local configuration lives in files that are **never committed**. Anything that
> is committed must be safe to run in production — off by default, or blocked
> structurally (`NODE_ENV`, `APP_ENV`), not by a promise.
>
> There is no "just temporarily" exception. Every incident in the
> [Forbidden list](#5-forbidden-list) started with that sentence.

---

## 1. The boundary: what may be committed

| | May be committed | Must not be committed |
|---|---|---|
| **Values** | Defaults that are safe in production | A specific machine's host/URL/credential |
| **Dangerous flags** | `${FLAG:-false}` | `FLAG: "true"` |
| **Guards** | `NODE_ENV === "production"` (dead-code-eliminated) | A soft flag that production could switch on |
| **Files** | `*.example`, `.env.development` (harmless localhost values only) | `.env`, `.env.local`, `.env.local-server`, `*.override.yml` |

The quick review test for an env line about to be committed:

> *"What happens if this line runs in production?"*
> If the answer needs the word "but", it must not be committed.

### 1.1 An evidence log must not share a knob with a noise knob (#1871)

`PAYMENT_ORCHESTRATION_LOG_LEVEL` — default `info`, and **deliberately not**
`LOG_LEVEL`.

The `payment_orchestration` channel is not debug output. Plan-055 Gate 6 gates a
money-affecting flip on two of its lines counting to zero
(`payment_policy_option_missing`, `payment_policy_alias_would_refuse`) — both
`->warning()`. While the channel read `env('LOG_LEVEL', 'debug')`, tightening
`LOG_LEVEL=error` for noise stopped those lines being written at all, `grep -c`
returned `0 / 0`, the exit criterion was satisfied, and the flip would refuse
money at every counter still running an old client. **Zero meant "never
recorded", not "no events".**

`deploy-xserver.yml` does not write `LOG_LEVEL` into the server `.env`, so the
real level is whatever is already in that file — **not readable from the repo**.
That is what makes the coupling dangerous rather than merely untidy.

Generalise it when adding a channel: ask whether anything **decides** on the
absence of its lines. If yes, give it its own variable — a level that can be
turned down is fine, a level that gets turned down *by accident, while something
reads the silence as a green light* is not.

---

## 2. The three local stacks

### 2.1 `docker-compose.yml` — the standard dev stack

This is the default stack for every developer.

```sh
docker compose up -d
docker compose exec app php artisan migrate:fresh --seed --force
```

| Service | Host port | Notes |
|---|---|---|
| backend (Laravel) | `5400` | `APP_ENV: local` — correct, this is the dev stack |
| mysql | `3307` | Separate from Herd's MySQL |
| minio (S3 API) | `5490` | The browser loads uploaded images through here |
| minio console | `5491` | |
| mailpit | `5482` | The UI; SMTP internally at `mailpit:1025` |
| phpMyAdmin | `5481` | Profile `tools` |
| admin-web | `5430` | Profile `admin-web` |

**Seeding always runs inside the container.** Running `php artisan` from the host
hits Herd's MySQL (a different database) and silently does nothing for the docker
stack.

### 2.2 `compose.local-server.yml` — the internal box, **with a Cloudflare tunnel**

This stack runs on a shared machine (a Mac mini) and **is tunnelled to the
Internet**. That makes it the most dangerous place in the repo, so every flag here
defaults to off.

```sh
docker compose -f compose.local-server.yml --env-file .env.local-server up -d --build
```

Everything per-box must live in `.env.local-server` (already gitignored). The
compose file may only contain safe defaults:

```yaml
APP_ENV: ${APP_ENV:-staging}
DEV_LOGIN: ${DEV_LOGIN:-false}
SSO_DEV_BYPASS: ${SSO_DEV_BYPASS:-false}
```

### 2.3 Herd — running Laravel directly on the host

The symlink
`~/Library/Application Support/Herd/config/valet/Sites/dxs-product → backend/`
serves Laravel at `https://dxs-product.test`. Use it only if you deliberately set
`NEXT_PUBLIC_API_URL=https://dxs-product.test` in `web/admin/.env.local`. By
default `.env.development` points at docker on `:5400`.

Backend tests run **natively, not through docker** (faster, and they use the
separate test database declared in `phpunit.xml`):

```sh
cd backend && php -d memory_limit=-1 vendor/bin/pest --compact
```

---

## 3. Signing in — use the staging IdP `id.famsys.net`

**No configuration is needed.** `docker compose up -d` is enough to sign in. Both
compose files already point at the shared staging IdP with fixed credentials
seeded by the platform (`TempoStagingSsoSeeder`):

```yaml
SSO_ISSUER:        ${SSO_ISSUER:-https://id.famsys.net}
SSO_SERVICE_SLUG:  ${SSO_SERVICE_SLUG:-tempo-staging}
SSO_CLIENT_ID:     ${SSO_CLIENT_ID:-si_tempo_famsys_staging}
SSO_CLIENT_SECRET: ${SSO_CLIENT_SECRET:-tempo-staging-secret}
```

These credentials are **deliberately public**. The instance is
`environment=staging` and grants nothing in production — the production client is
a separate instance with a randomly generated secret, and its env is written by
the deploy workflow, not by these files.

The IdP's redirect allowlist uses wildcards (`https://*.famsys.net/*`,
`http://localhost:*/auth/callback`, `127.0.0.1`) matched with `fnmatch`, so
changing a tunnel hostname or adding a new app port is **never blocked**. On the
platform side, `DevRedirectHosts` also accepts the `*.famsys.net` family alongside
`*.godx.jp` — that helper is already gated on `! app()->isProduction()`.

To point at a different IdP, override it through `.env` (uncommitted) — every
value is a `${VAR:-default}`.

> **Operational requirement:** the seeder must already have run on the
> `id.famsys.net` box
> (`php artisan db:seed --class=TempoStagingSsoSeeder`). Until it has,
> `/sso/authorize` returns `422 INVALID_SERVICE`.

---

## 3b. The SSO login bypass — the old route, use sparingly

Since section 3 exists, **you almost never need this**. It remains for the case
where the IdP is completely unreachable. **This is the most sensitive thing in
this document.**

### 3.1 Three conditions — all three are required

`vendor/dxs/laravel-auth/src/Http/Middleware/AuthenticateSso.php`:

```php
private function developmentBypassEnabled(): bool
{
    return (bool) config('sso.dev_bypass.enabled', false)          // ① SSO_DEV_BYPASS
        && app()->environment(config('sso.dev_bypass.environments')); // ② APP_ENV ∈ [local, testing]
}
```

and then inside the bypass branch:

```php
if ($subject !== '' && $this->developmentSubjects->allows($subject)) {  // ③ allowlist
    $user = $this->provisioner->resolveBySubject($subject)
        ?? $this->provisioner->provision(['sub' => $subject], [...]);   // creates the user if absent
    Auth::setUser($user);
    return $next($request);   // NO token verification, NO revocation check
}
```

| # | Variable | Default | Meaning |
|---|---|---|---|
| ① | `SSO_DEV_BYPASS` | `false` | The middleware accepts a `dev:` bearer |
| ② | `APP_ENV` | `staging` / `production` | The package refuses the bypass outside `local`/`testing` |
| ③ | `SSO_DEV_BYPASS_SUBJECTS` | *(empty)* | A CSV of permitted `console_user_id` values |
| — | `DEV_LOGIN` | `false` | Opens the minting endpoint `POST /api/dev/test-login` |

Note on ③: an empty allowlist means **no subject gets through** — but do not treat
that as protection. It is only the last layer.

And note `provision()`: the subject **does not have to exist beforehand**; the
middleware creates the user. So the allowlist is not "pick from the existing
users" but "decide which users will be created".

### 3.2 How to enable it CORRECTLY (on a personal machine only)

Create `.env.local-server` or `.env` — **do not commit them**:

```dotenv
APP_ENV=local
SSO_DEV_BYPASS=true
SSO_DEV_BYPASS_SUBJECTS=01931f...,01931a...   # seeded console_user_id values
DEV_LOGIN=true
```

Sign in with `Authorization: Bearer dev:<console_user_id>`.

> `ReadBearerFromCookie` promotes the `token` cookie, so **being able to set that
> cookie is a full login**. Do not enable this on a box anyone can reach.

---

### 3.3 What protects production

| Layer | Where |
|---|---|
| Deploy forces it off | `.github/workflows/deploy-xserver.yml:204` → `SSO_DEV_BYPASS=false` |
| Compose default off | `docker-compose.yml` → `${SSO_DEV_BYPASS:-false}` |
| Tunnel compose default off | `compose.local-server.yml` → all three flags `${...:-false}` |
| The package refuses | `app()->environment(['local','testing'])` |
| The frontend drops it from the bundle | `NODE_ENV === "production"` → dead-code-eliminated |

The test suite deliberately enables it (`backend/phpunit.xml` →
`SSO_DEV_BYPASS=true`) — correctly, because `APP_ENV=testing` is in the
environment allowlist.

---

## 4. Frontend (admin-web / customer-web / pos-web)

### 4.1 Env files

| File | Committed? | Used for |
|---|---|---|
| `.env.development` | ✅ yes | Dev defaults, containing only `localhost:*` |
| `.env.local` | ❌ no | Per-developer overrides |

`web/admin/.env.development` currently holds:

```dotenv
TEMPO_BACKEND_URL=http://localhost:5400
NEXT_PUBLIC_CUSTOMER_WEB_URL=http://localhost:5450
```

The browser **always calls same-origin** `/api` and `/auth`. Next handles the
proxy locally and Amplify handles it in production. Never reintroduce a public API
base in the frontend — the Platform token is an HttpOnly cookie on
`tempo.godx.jp` and cannot be read cross-origin.

### 4.2 The `/s3` → MinIO rewrite

`next.config.ts` rewrites images to a local MinIO. It is safe **by construction**:

```ts
if (process.env.NODE_ENV === "production") {
  return [];              // ← the entire rewrites block disappears in production
}
...
{ source: "/s3/:path*", destination: "http://localhost:5490/tempo/:path*" }
```

Paired with `src/lib/image-url.ts`, which only matches `localhost:5490/tempo/`, it
is a no-op against a real S3/CDN URL. This is the **correct** pattern:
configuration pointing at localhost may be committed *because* it cannot exist in
production.

### 4.3 The dev-login button

`src/app/login/page.tsx` plus `components/dev-login-button.tsx` must keep the hard
guard:

```tsx
if (process.env.NODE_ENV === "production") {
  return null;   // or redirect(ssoDestination)
}
```

`NODE_ENV` is inlined at build time, so this branch (and the `LoginClient` /
`DevLoginButton` it pulls in) is **removed entirely from the production bundle**.

**Never replace it with a soft flag.** See section 5.2.

---

## 5. Forbidden list

None of this is hypothetical — all of it has happened in this repo.

### 5.1 Committing your own machine's configuration file

`public/__config.json` was once committed containing:

```json
{"apiUrl": "https://<a specific machine's temporary tunnel URL>"}
```

Because it sat in `public/`, it was **served publicly** at `/__config.json`,
pinned one machine's temporary tunnel URL, and no code ever read it. Deleted and
gitignored.

→ **Never** commit a file containing a specific machine's host, URL or credential.

### 5.2 Downgrading a production guard into a soft flag

```diff
- if (process.env.NODE_ENV === "production") return null;
+ const bypassEnabled =
+   process.env.NODE_ENV !== "production" ||
+   process.env.NEXT_PUBLIC_ENABLE_DEV_BYPASS === "true";
+ if (!bypassEnabled) return null;
```

Three copies of this patch once reached `dev` through a PR with zero reviews, each
annotated *"Staging-only local patch (uncommitted, never pushed)"* — while it was
in fact committed and exactly one push away from production. Reverted.

→ **Never** turn a `NODE_ENV`/`APP_ENV` guard into a flag that production could
set. If a strange box needs a bypass, change **that box's environment**, not the
code.

### 5.3 Hard-enabling a dangerous flag in a committed file

```yaml
APP_ENV: local          # ❌
DEV_LOGIN: "true"       # ❌
SSO_DEV_BYPASS: "true"  # ❌
```

These were once in `compose.local-server.yml` for the `app` service — the very
container the Cloudflare tunnel serves. Two of the three bypass conditions were
satisfied; only the empty allowlist stood in the way. Changed to `${...:-false}`
(#1141).

→ **Never** use a literal `"true"` for a bypass flag. Always `${FLAG:-false}`.

### 5.4 Mixing WIP into an unrelated commit

A commit titled *"feat(catalog): tax-exempt-brand command"* dragged in a
`dev/test-login` route plus bindings pointing at three uncommitted classes.
Laravel resolves those at boot → **every HTTP request and every artisan command on
`dev` died**.

→ Use `git add -p` and read `git diff --cached` before committing. Never
`git add -A` while the working tree holds WIP.

### 5.5 Committing a raw production snapshot as a seeder fixture

`backend/database/seeders/fixtures/orders/` landed as a verbatim dump of the
production tenant (#2220 / PR #2219). It carried **11 staff email addresses**
with **11 live `console_access_token` / `console_refresh_token` pairs** issued by
`id.godx.jp` (`aud: betoya-tempo-production`), 28 customer names and phone
numbers, and **287 `qr_token`s** — 235 tables plus 52 orders. `qr_token` is a
secret by this repo's own rules: `CustomerOrder::$hidden` withholds it and
`CustomerOrderResource` says *"never expose it through"*. Anyone with read access
to the repo could open a live table's ordering session.

Two things follow, and the second is the one people skip:

1. **A revert does not un-leak anything.** Git keeps the blob, and a token that
   was published is compromised whether or not the file still exists. Rotation is
   a separate, human step at the identity provider — it does not happen by
   merging a cleanup PR.
2. **Capture and anonymise are two steps, and step two is not optional.** For the
   order snapshot that is
   `php database/seeders/fixtures/orders/_scrub_orders.php`, which replaces
   people and regenerates every `qr_token` deterministically while leaving money,
   tax, timestamps and foreign keys alone — the *shape* is the value of a
   snapshot, the identities are not.
   `backend/tests/Feature/Architecture/SeederFixturesCarryNoProductionSecretsTest.php`
   fails
   if the step is skipped: it compares each fixture against its own anonymised
   form, which is the only way to catch a production `qr_token` (a real one and a
   fake one are both 32 base62 characters).

A seeder that writes orders also has no business running on production at all,
whatever its data looks like: mark it `RefusesToRunInProduction`, because
`db:seed --class=… --force` bypasses `DatabaseSeeder` entirely.

→ **Never** commit a fixture straight from a production capture. Scrub first, and
say in the PR whether credential rotation is still outstanding.

### 5.6 Using the machine or DB clock as business time

See `docs/guide/business-time.md`. Shops run in VN (UTC+7) and JP (UTC+9) on one
UTC backend — "today" is not a global concept. `APP_TIMEZONE=Asia/Tokyo` is
forbidden, as are `CURDATE()` and `now()->toDateString()`.

Cron is a separate matter and equally easy to get wrong:
`APP_OPERATIONS_TIMEZONE` sets the **firing rhythm** of scheduled jobs
(head-office time), **not** a source of business time — a job must still resolve
the date per branch through `BusinessClock`. `APP_OPERATIONS_TIMEZONE_JP` and
`APP_OPERATIONS_TIMEZONE_VN` are country-aware fallbacks only when a branch has
no usable timezone; the branch value always wins. See *Cron when serving several
countries* in `business-time.md`.

---

## 6. Pre-commit checklist

- [ ] `git diff --cached` — does it contain your machine's host, URL or token?
- [ ] Does it add or refresh a seeder fixture? → was it captured from production,
      and did you run the scrubber before staging it?
- [ ] Is there a literal `"true"` for any bypass/debug flag? → change it to `${FLAG:-false}`
- [ ] Does it loosen a `NODE_ENV`/`APP_ENV` guard? → stop
- [ ] Is any new env file listed in `.gitignore`?
- [ ] Does a comment say "temporary" or "uncommitted, never pushed"? → it is being
      committed; either drop the code or drop the false comment
- [ ] Does this commit have exactly one subject?

The final question, asked of every env line: **"what happens in production?"**

---

## 7. Quick reference

| Variable | Default | Set where | Production impact |
|---|---|---|---|
| `APP_ENV` | `local` (docker dev) / `staging` (local-server) | compose, `.env` | ⚠️ Bypass condition ② |
| `APP_DEBUG` | `false` | `.env` | ⚠️ Leaks stack traces |
| `SSO_DEV_BYPASS` | `false` | `.env`, `.env.local-server` | 🔴 Login bypass |
| `SSO_DEV_BYPASS_SUBJECTS` | *(empty)* | `.env.local-server` | 🔴 The last line of defence |
| `DEV_LOGIN` | `false` | `.env.local-server` | 🔴 Opens the minting endpoint |
| `ENABLE_LOCAL_LOGIN` | `false` | `.env.local-server` | 🔴 |
| `NEXT_PUBLIC_ENABLE_DEV_BYPASS` | — | **no longer exists** | ⛔ Forbidden, see 5.2 |
| `CONSOLE_URL` | — | **no longer exists** | ⛔ Gỡ khỏi compose ở #2036 — `grep` toàn repo kể cả `backend/vendor` cho **0** `env()` đọc nó. Đừng thêm lại: giá trị được tiêm vào container sẽ làm một `env('CONSOLE_URL')` tương lai lặng lẽ nhận host cũ thay vì null |
| `OMNIFY_AUTH_MODE` | `console` | compose | ✅ **SỐNG — đừng quét.** `backend/config/l5-swagger.php:40` đọc nó để chọn artifact Swagger. Đã có một lượt quét tài liệu báo nhầm nó là biến chết (#2036) |
| `SSO_ISSUER` | `https://id.famsys.net` | compose (default provided) | ✅ Staging IdP |
| `SSO_CLIENT_ID` | `si_tempo_famsys_staging` | compose (default provided) | ✅ Deliberately public |
| `SSO_CLIENT_SECRET` | `tempo-staging-secret` | compose (default provided) | ✅ Deliberately public |
| `TEMPO_BACKEND_URL` | `http://localhost:5400` | `.env.development` | ✅ Harmless |
| `NEXT_PUBLIC_CUSTOMER_WEB_URL` | `http://localhost:5450` | `.env.development` | ✅ Harmless |
| `VITE_WORKSTATION_API_URL` | `http://localhost:8080` when empty | `web/pos/.env*`, build environment | ⚠️ POS LAN workstation seed. Set `none` to disable it in a cloud build with no workstation; never commit a real shop IP because pairing is stored per device |
| `CUSTOMER_WEB_URL` | *(empty)* | backend `.env` | ⚠️ Where the email-verification link sends the customer back to (#1680). **Empty on purpose** — with no value `GET /customer/auth/verify/...` answers in JSON instead of redirecting, which is what a dev box wants. Never commit one machine's host here (see 5.1): a stale tunnel URL in this variable mails real customers a dead link |
| `STRIPE_*` | empty | `.env` | ⚠️ Never commit real keys |
| `APP_DEFAULT_BRANCH_TIMEZONE` | `Asia/Tokyo` | `.env` | ✅ Default when creating branches and for non-branch presentation contexts. `BusinessClock` uses the country map below when an existing branch has no usable timezone |
| `APP_OPERATIONS_TIMEZONE` | `Asia/Tokyo` | `.env` | ⚠️ **The cron rhythm**, not business time. Left blank it falls back to `APP_DEFAULT_BRANCH_TIMEZONE` — changing that one then shifts the cron rhythm with it (#1161) |
| `APP_OPERATIONS_TIMEZONE_JP` · `APP_OPERATIONS_TIMEZONE_VN` | `Asia/Tokyo` · `Asia/Ho_Chi_Minh` | `.env` | ✅ Head-office fallback by `organizations.operating_country` only when `branches.timezone` is missing/invalid. Never overrides a branch timezone (#2838) |
| `FILESYSTEM_DISK` | `local` | `.env` | ✅ The framework default disk. `local` is **private** (`storage/app/private`) — correct for internal artifacts, wrong for anything a browser fetches |
| `UPLOADS_DISK` | `public` | `.env` | ⚠️ Where `POST /api/v1/files/upload` puts user uploads **and where every read path looks for them**. **Must stay publicly servable.** Deliberately separate from `FILESYSTEM_DISK`: when the two were one knob, production ran `local` and every product image 404'd — upload returned 201, the file hit disk, the row was written, and Laravel logged **nothing** (#2101). `s3` is fine; `local` is not, and `UploadDiskIsPubliclyServableTest` fails the build if it is. One knob, both directions: `MediaProxyController` streams from this disk and `RebaseStorageUrl` takes its live host from this disk's `url` (#2175) — before that, both read `s3` while the write path had already moved here, so images landed on one disk and were looked for on another |
| `WORKSTATION_EXPECTED_VERSION` | *(trống)* | backend `.env` | ⚠️ Bản phát hành HQ mong đợi. Trống ⇒ feed trả `version: null` ⇒ máy trạm **không cảnh báo** (fail-safe cố ý). Đặt sai tag ⇒ mọi quán kêu `stale_build` cùng lúc |
| `WORKSTATION_EXPECTED_AUTO_APPLY` | `false` | backend `.env` | 🔴 **Cho phép máy trạm TỰ thay binary và khởi động lại lúc 02:00–04:00 giờ quán, KHÔNG người trông** (#2635). Bật là một quyết định vận hành cho **một bản cụ thể**, không phải cấu hình để đó. Giá trị lạ fail-safe về `false` — ngược chiều `severity` vốn hạ về `info`, vì chiều an toàn của một cờ restart là KHÔNG. Guard còn lại vẫn chạy (còn ca mở ⇒ không cài; binary mới chết lúc boot ⇒ tự lùi `.bak`), nhưng **đừng bật khi chưa chạy thử một vòng đầy đủ trên máy không phải production** |
| `AUDIT_LOG_RETENTION_DAYS` | `400` | `.env` | ⚠️ **Cửa sổ lưu `audit_logs`** (#2555). Chỉnh **lên** được; dưới `audit.pci_floor_days` = 365 thì `audit:prune` **từ chối chạy và không xoá gì** — sàn đó là PCI DSS v4.0 Req 10.5.1 (≥12 tháng), không phải con số ưa thích. Đặt thấp trên máy dev để "cho nhanh" là vô nghĩa: lệnh sẽ fail-closed y như production |
| `AUDIT_PRUNE_CHUNK_SIZE` · `AUDIT_PRUNE_MAX_ROWS` · `AUDIT_PRUNE_MAX_SECONDS` | `1000` · `200000` · `300` | `.env` | ✅ Cận cho mỗi lượt `audit:prune`. Chúng là thứ giữ lệnh an toàn giữa giờ phục vụ, **không phải** giờ chạy 02:50 — `audit_logs` chưa có index `created_at` nên lượt drain đầu tiên quét không có index đỡ. Nới `MAX_SECONDS` trên production thì phải biết mình đang kéo dài một lượt quét bảng |
| `PRINT_IMAGES_DISK` | `local` | `.env` | ✅ Where `PrintImageStore` keeps print-image ORIGINALS. Deliberately **private** and deliberately **not** `FILESYSTEM_DISK`: these bytes never reach a client (workstations pull the rastered bitmap out of `print_image_rasters.data`), and while the store rode the default disk, flipping `FILESYSTEM_DISK` silently orphaned every original — `rasterFor()` then returned `null` for any width that was not pre-rendered, with no log (#2136). Đổi disk thì phải sao chép nguyên bản sang disk mới rồi ghi lại đường dẫn — lệnh `print-images:migrate-disk` ĐÃ GỠ #2507 (production còn 0 asset) |

---

## Related

- [setup-docker.md](setup-docker.md) — setting up the docker stack for the first time
- [sso-authentication.md](sso-authentication.md) — the full SSO flow
- [business-time.md](business-time.md) — business timezones
