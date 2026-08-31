---
title: Platform SSO Authentication
category: guide
tags: [sso, oauth2, oidc, platform]
summary: Configure Tempo as a downstream application of the DXS Platform.
---

# Platform SSO Authentication

Tempo uses the Platform as its only workforce identity and service-authorization source. The browser follows the same BFF pattern as `dxs-kintai`: JavaScript never receives an access token.

> **Có hai doc SSO, khác phạm vi — đừng nhầm.** Đây là **reference tích hợp
> Platform**: biến môi trường `SSO_*`, luồng BFF, catalog phân quyền
> `config/authz.php`, chạy trên `platform.test` / `dxs-product.test`.
> Muốn **dựng SSO cho môi trường dev** (docker `:5400`, IdP
> `dev-console.godx.jp`, tài khoản đăng nhập sẵn) thì đọc
> [SSO Authentication Setup](../../../docs/guide/sso-authentication.md) ở
> umbrella. Hai bản không thay thế nhau (#1322).

## Request flow

```text
Browser -> Next /auth/redirect -> Laravel dxs/laravel-auth -> Platform
Browser <- Next /auth/callback <- Laravel exchanges code + verifies PKCE/JWKS
Browser -> Next /api/* -> Laravel reads HttpOnly token cookie -> Platform permissions
```

Laravel owns `state`, `nonce`, PKCE S256, confidential code exchange, JIT user provisioning, refresh tokens, and the HttpOnly `token` cookie. Next proxies `/auth/*`, `/api/*`, and `/broadcasting/*` to Laravel.

## Backend environment

```dotenv
SSO_ISSUER=https://platform.test
SSO_SERVICE_SLUG=tempo-local
SSO_CLIENT_ID=si_xxx
SSO_CLIENT_SECRET=sk_xxx
SSO_REDIRECT_URI=http://localhost:5430/auth/callback
SSO_ORGANIZATION_CONTEXT_ID=<platform-organization-uuid>
SSO_ALLOW_ORGANIZATION_SWITCHING=true
SSO_AFTER_LOGIN=/select-context
SSO_AFTER_LOGOUT=/login
SSO_FAILURE_REDIRECT=/login
SSO_TOKEN_COOKIE=token
SSO_REFRESH_ENABLED=true
```

The redirect URI must exactly match the Platform service instance allowlist. Secrets are server-only.

## Frontend environment

```dotenv
TEMPO_BACKEND_URL=https://dxs-product.test
```

Do not add public issuer, client ID, client secret, or token variables to the frontend.

## Authorization catalog

`config/authz.php` declares Tempo's 33 tenant permissions and its `member`, `manager`, and `admin` roles. The admin role contains all 33 permissions. Platform-wide `system.cross_tenant.access` is intentionally excluded.

For an environment with a catalog-management token:

```bash
php artisan dxs:sync-authz --dry-run
php artisan dxs:sync-authz
```

Configure `SSO_SERVICE_ID` and `SSO_ADMIN_TOKEN` before the write. The local `platform.test` catalog and all current Betoya Tempo accesses were synchronized during onboarding.

## Verification

1. Open `http://localhost:5430/login`.
2. Sign in on `platform.test`.
3. Confirm the browser returns through `/auth/callback` to `/select-context`.
4. Confirm `GET /api/v1/me/context` succeeds using only the HttpOnly cookie.
5. Confirm no access token appears in `localStorage`, session storage, or frontend JavaScript.

## Troubleshooting

| Symptom | Check |
|---|---|
| `REDIRECT_URI_NOT_ALLOWED` | Platform instance allows the exact `SSO_REDIRECT_URI`. |
| State/nonce/PKCE error | Restart at `/auth/redirect`; do not reuse callback URLs. |
| API returns 401 | Issuer, audience/service slug, cookie proxy, token expiry. |
| API returns 403 | User's Platform service access and resolved Tempo permissions. |
| Catalog sync returns 401/403 | `SSO_ADMIN_TOKEN` has catalog-management authority. |
