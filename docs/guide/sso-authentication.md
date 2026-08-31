---
title: SSO Authentication Setup (local dev)
category: guide
tags: [setup, sso, authentication, oidc, platform, dev-bypass]
summary: Get a local TempoFast signed in — the shared staging IdP wired into docker-compose, the /auth/redirect → /auth/callback BFF flow through admin-web, and the dev-bypass escape hatch for when the IdP is unreachable.
related:
  - guide/setup-docker.md
  - guide/local-config.md
---

# SSO Authentication Setup (local dev)

> **VIẾT LẠI 2026-08-07 (#2029).** Bản trước mô tả một hệ **không còn tồn tại**:
> package `backend/packages/dxs-sso`, công tắc `OMNIFY_AUTH_MODE`
> (`standalone` ↔ `console`), IdP `dev-console.godx.jp` với instance
> `tempo-dev`, luồng `/login/callback` + `POST /api/v1/auth/sso/callback`, env
> `NEXT_PUBLIC_CONSOLE_URL` / `NEXT_PUBLIC_SSO_SERVICE_SLUG`, và token cất trong
> `localStorage`. **Không cái nào trong số đó resolve được hôm nay** — đo bằng
> `ls backend/packages` (không có thư mục), `grep -rn OMNIFY_AUTH_MODE backend`
> (chỉ còn `config/l5-swagger.php`, và ở đó nó chọn artifact Swagger nào được
> publish, **không** phải chế độ auth), `grep -rn NEXT_PUBLIC_CONSOLE_URL
> admin-web` (zero hit trong `src/` lẫn `.env.development`), và
> `grep -rn "auth/sso/callback" backend/routes` (zero hit).
>
> Đây **không phải** một tính năng bị gỡ mà là một hệ bị **THAY**: Tempo giờ là
> downstream OIDC của DXS Platform qua package composer `dxs/laravel-auth`. Bản
> cũ mô tả thế hệ trước nó. Đừng đi dựng lại `OMNIFY_AUTH_MODE=standalone` —
> không có nhánh code nào đọc nó nữa.

> **Có hai doc SSO, khác phạm vi — đừng nhầm.** Đây là doc **dựng môi trường
> dev**: docker `:5400`, IdP staging dùng chung, đường thoát khi IdP không với
> tới được. Còn
> [Platform SSO Authentication](../../backend/docs/guide/sso-authentication.md)
> là **reference tích hợp Platform**: hợp đồng giao thức, biến `SSO_*` đầy đủ,
> catalog phân quyền `config/authz.php` + `dxs:sync-authz`. Hai bản không thay
> thế nhau (#1322).

## Nó chạy trên cái gì

| Thành phần | Chỗ thật |
|---|---|
| Package | **`dxs/laravel-auth`** (composer, `^0.13.2`, repo `github.com/dxs-platform/laravel-auth`) — vendor, không phải in-tree. Namespace `Dxs\Auth`. |
| Cấu hình backend | `backend/config/sso.php`, toàn bộ đọc từ env `SSO_*` |
| Route | Do `SsoClientServiceProvider` nạp từ `vendor/dxs/laravel-auth/routes/web.php`, prefix `auth` (`sso.routes.prefix`), middleware `web` |
| Nơi giữ token | Cookie **HttpOnly** tên `token` (`sso.token_cookie`) — JavaScript **không bao giờ** nhận access token |
| admin-web | `src/lib/sso.ts` → `loginDestination()` trả về `/auth/redirect?...`; browser luôn gọi **same-origin**, Next proxy sang backend |

Bốn route mà package cấp — đây là **toàn bộ** bề mặt SSO:

```
GET  /auth/redirect              → sso.redirect
GET  /auth/callback              → sso.callback
POST /auth/logout                → sso.logout
POST /auth/backchannel-logout    → sso.backchannel-logout
```

Không có `/login/callback`, không có `POST /api/v1/auth/sso/callback`.

## Luồng đăng nhập

```
admin-web /login  →  <a href="/auth/redirect?return=/select-context">
  → Next rewrite /auth/:path* → TEMPO_BACKEND_URL/auth/:path*   (dev)
  → Laravel SsoRedirectController: dựng state + nonce + PKCE S256, redirect sang IdP
  → Người dùng đăng nhập trên Platform
  → IdP redirect về SSO_REDIRECT_URI = http://localhost:5430/auth/callback
  → Next rewrite → Laravel SsoCallbackController: đổi code (confidential client),
    verify JWKS/nonce/PKCE, JIT-provision user, đồng bộ org/brand/branch
  → Set cookie HttpOnly `token` → redirect tới SSO_AFTER_LOGIN (/select-context)
```

`redirect_uri` phải khớp **chính xác** giữa authorize và token exchange, và phải
nằm trong allowlist của service instance bên Platform.

**Proxy là dev-only.** `web/admin/next.config.ts` trả mảng rỗng khi
`NODE_ENV === "production"` — production do Amplify rewrite rules sở hữu (cùng
khuôn với dxs-kintai). Ba tiền tố được proxy: `/auth/*`, `/api/*`,
`/broadcasting/*`.

## Cấu hình từng app

| App | File | Key |
|---|---|---|
| backend (Docker — mặc định) | `docker-compose.yml` (committed) | `SSO_ISSUER`, `SSO_SERVICE_SLUG`, `SSO_CLIENT_ID`, `SSO_CLIENT_SECRET`, `SSO_REDIRECT_URI` |
| backend (native / Herd) | `backend/.env.example` → copy sang `.env` | cùng bộ key, mặc định trỏ `https://platform.test` |
| admin-web | `web/admin/.env.development` (committed) | **`TEMPO_BACKEND_URL`** — chỉ có thế. Đích của BFF, **server-only**. |
| pos-web · customer-web · kiosk · tms-app · workstation-app · kds | — | không dùng SSO (device pairing / customer auth) |

**Frontend không giữ bất kỳ bí mật SSO nào, và cũng không giữ issuer/slug.** Nếu
bạn thấy một `NEXT_PUBLIC_*` liên quan SSO ở đâu đó, nó là di sản.

> **`web/pos/.env.example` còn hai dòng `VITE_CONSOLE_URL` /
> `VITE_SSO_SERVICE_SLUG`** (và `web/pos/README.md` nhắc lại). Hai key đó **có
> thật trong file** nhưng **không code nào đọc** — đo: `grep -rn` cho cả hai tên
> trong `web/pos/` chỉ hit đúng `.env.example` và `README.md`, `src/` zero hit.
> Đường auth thật của pos-web là device pairing
> (`web/pos/src/services/auth/pairing.ts`). Dọn hai dòng đó thuộc `web/pos/`,
> không thuộc doc này.

## Zero configuration cho local dev

`docker compose up -d` là đủ. `docker-compose.yml` ghim sẵn credential của một
service instance **staging dùng chung** trên IdP `id.famsys.net`:

| Env | Giá trị dev | Ý nghĩa |
|---|---|---|
| `SSO_ISSUER` | `https://id.famsys.net` | IdP (Platform) |
| `SSO_SERVICE_SLUG` | `tempo-staging` | **Instance slug**, không phải service slug — xem cảnh báo dưới |
| `SSO_CLIENT_ID` | `si_tempo_famsys_staging` | OAuth client id của instance |
| `SSO_CLIENT_SECRET` | `tempo-staging-secret` | client secret của instance |
| `SSO_REDIRECT_URI` | `http://localhost:5430/auth/callback` | phải khớp allowlist bên Platform |
| `SSO_TIMEOUT` | `5` | timeout HTTP khi gọi IdP |

Bộ này **cố ý công khai**: instance chỉ dùng cho staging, không cấp quyền gì trên
production (production là instance riêng với secret sinh ngẫu nhiên). Mọi giá trị
đều override được qua `.env` ở umbrella (gitignored) vì chúng viết dưới dạng
`${VAR:-default}`.

> **⚠️ Dùng instance slug, KHÔNG dùng service slug.** IdP resolve slug bằng cách
> thử instance trước rồi mới fallback về service. Đưa service slug (`tempo`) thì
> IdP chọn "instance active đầu tiên" — **không xác định** — và token exchange
> chết với `INVALID_GRANT`. Luôn ghim một instance cụ thể.

> **Hai biến trong `docker-compose.yml` KHÔNG dính gì tới đăng nhập — nhưng vì
> hai lý do khác nhau.**
> `CONSOLE_URL` **chết thật**: `grep -rn CONSOLE_URL` toàn repo (kể cả
> `backend/vendor`, nơi `dxs/laravel-auth` thực sự nằm) → không một `env()` nào
> đọc. Đã gỡ khỏi `docker-compose.yml` ở #2036.
> `OMNIFY_AUTH_MODE` thì **SỐNG** — `backend/config/l5-swagger.php:40` đọc nó:
> `$mode = env('OMNIFY_AUTH_MODE', 'console');` để chọn artifact Swagger.
> **Đừng gỡ.** Bản trước của khối này gộp cả hai vào một tiêu đề "ĐỒ CHẾT"; thân
> bài nói đúng nhưng tiêu đề thì không, và một lượt quét sau đó đã tin tiêu đề
> rồi đề nghị gỡ một biến đang có tác dụng.

## Đường thoát khi IdP không với tới được — dev bypass

Container không resolve được `platform.test` của host, và không phải lúc nào IdP
staging cũng sẵn sàng. Khi đó dùng **dev bypass**: `AuthenticateSso` chấp nhận
thẳng một bearer dạng `dev:<console_user_id>`, bỏ qua verify token và tự
provision user nếu chưa có.

Cần **cả ba** điều kiện, thiếu một là inert:

1. `SSO_DEV_BYPASS=true`
2. `APP_ENV ∈ {local, testing}` (`sso.dev_bypass.environments`)
3. `SSO_DEV_BYPASS_SUBJECTS` chứa `console_user_id` của persona

Mặc định **TẮT** (`${SSO_DEV_BYPASS:-false}`); bật per-developer qua `.env`
gitignored ở umbrella. Workflow deploy ép `SSO_DEV_BYPASS=false` trên production
— xem `docs/guide/local-config.md`.

Hai cách lấy bearer đó, cả hai đều tồn tại:

- **Nút "Dev login" trên `/login` của admin-web**
  (`src/app/login/components/dev-login-button.tsx`) — tự cắm cookie `token` =
  `dev:<subject>` đúng như callback OIDC thật rồi điều hướng vào. Component bị
  dead-code-eliminate khỏi bundle production bằng `process.env.NODE_ENV`.
  Persona nằm ngay trong file (`DEV_PERSONAS`), mirror các seeder.
- **`POST /api/dev/test-login`** (`DevLoginController`) — mint bearer cho một
  email trong allowlist. Route này gác riêng bằng `DEV_LOGIN=true` +
  `config/dev_login.php` (`dev_login.emails`), và bearer nó mint vẫn phải qua
  `SSO_DEV_BYPASS`.
  ⚠️ Docblock của `dev-login-button.tsx` nói *"There is NO backend mint route"* —
  **sai**, route có thật ở `backend/routes/api.php`. Chỉ là cái nút không gọi nó.

## Các bước

```sh
docker compose up -d              # backend :5400, tự migrate + tự seed lần đầu
cd admin-web && pnpm dev          # → http://localhost:5430
```

Seed lại từ đầu:

```sh
docker compose exec app php artisan migrate:fresh --seed --force
# hoặc xoá sạch (DB + marker) rồi để lượt `up` sau dựng lại:
docker compose down -v && docker compose up -d
```

> Seed **luôn chạy trong container**. Chạy `php artisan` native trên host là trỏ
> vào MySQL của Herd (một DB khác) — nó "seed thành công" trong khi stack Docker
> không nhận được gì.

Mở `http://localhost:5430/login` → nút **SSO** đi vào luồng OIDC thật; nút dev
login đi đường bypass (chỉ hiện ngoài production build).

## Xác minh

1. Trình duyệt quay về qua `/auth/callback` rồi dừng ở `/select-context`.
2. `GET /api/v1/me/context` thành công **chỉ** bằng cookie HttpOnly.
3. **Không** có access token nào trong `localStorage` / sessionStorage / JS.

## Troubleshooting

| Triệu chứng | Kiểm |
|---|---|
| `REDIRECT_URI_NOT_ALLOWED` | Instance bên Platform phải allowlist đúng chuỗi `SSO_REDIRECT_URI` |
| Lỗi state / nonce / PKCE | Bắt đầu lại từ `/auth/redirect`; không dùng lại URL callback (code là single-use) |
| `INVALID_GRANT` khi đổi code | Đang dùng service slug thay vì instance slug, hoặc `redirect_uri` lệch |
| API trả 401 | Issuer / service slug / cookie proxy / token hết hạn |
| API trả 403 | Quyền service của user trên Platform + permission Tempo resolve ra |
| Đổi env trong `docker-compose.yml` không ăn | `docker compose restart` **không** nạp env mới — chạy `docker compose up -d` (recreate container). admin-web thì restart `pnpm dev`. |
| Đăng nhập xong select-context rỗng | User chưa có service access, hoặc DB local chưa seed (org phải tồn tại để map `console_organization_id`) |
| IdP không với tới được | Dùng dev bypass ở trên |

## Production

- Instance production là instance **riêng**, secret sinh ngẫu nhiên, chỉ hiện
  lúc tạo/regenerate. Không bao giờ dùng lại credential staging.
- Đăng ký chính xác `allowed_redirect_uris` — production không có ngoại lệ
  localhost.
- `SSO_DEV_BYPASS` và `DEV_LOGIN` phải là `false`; workflow deploy đã ép
  `SSO_DEV_BYPASS=false`.
- Reverse proxy `/auth/*` · `/api/*` · `/broadcasting/*` do Amplify rewrite rules
  sở hữu, không phải `next.config.ts`.
