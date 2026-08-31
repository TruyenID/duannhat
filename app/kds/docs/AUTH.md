# Authentication

KDS device authentication flow, pairing lifecycle, and token storage. Full design spec in umbrella `plans/plan-027/DESIGN.md` § Auth + Pairing.

## Pairing Flow (6-char Code Exchange)

1. **Admin creates device** in admin-web (`type: kds`, `branch_id`). Cloud generates 6-char alphanumeric pairing code (valid 15 minutes, single-use).

2. **KDS boots with no token** — tablet bếp opens kds-web URL. `AuthProvider` reads `localStorage`, finds no `kds_device_token` → state transitions to `unpaired` → redirects to `/pairing` route.

3. **Staff enters code** — Pairing form displays input field. User types 6 chars (case-insensitive).

4. **Exchange code for token** — KDS POSTs to **cloud direct** (never workstation, token doesn't exist yet):
   ```
   POST /api/v1/devices/pair
   {
     "pairing_code": "A1B2C3",
     "device_info": {
       "user_agent": "Mozilla/5.0 ...",
       "app_version": "1.0.0"
     }
   }
   ```

5. **Cloud responds with token + device info**:
   ```json
   {
     "device_token": "eyJ...",
     "data": {
       "id": "dev-uuid",
       "name": "Kitchen Display 1",
       "type": "kds",
       "branch_id": "branch-uuid",
       "branch_name": "Main Branch",
       "shop_id": "shop-uuid",
       "created_at": "2026-05-26T...",
       "last_seen_at": "2026-05-26T..."
     }
   }
   ```

6. **Store token + info locally** — `localStorage`:
   ```javascript
   localStorage.setItem("kds_device_token", token);
   localStorage.setItem("kds_device_info", JSON.stringify(data));
   ```

7. **AuthProvider transitions to `paired`** — redirects to `/` (kitchen order list).

## Lifecycle States

```
┌─────────┐
│ loading │  (initial: reading localStorage + verifying token via GET /me)
└────┬────┘
     │
     ├──→ ┌────────┐  (token exists + /me succeeds, or network error)
     │    │ paired │
     │    └────────┘
     │         ▲
     │         │
     └──→ ┌─────────┐  (no token, or /me returns 401)
          │ unpaired│
          └─────────┘
               │
               │ (pair → get token + redirect to /)
               │
               └─────→ paired
```

### State Meanings

- **loading**: App is starting, reading localStorage, calling `/me` to verify token. Render nothing (or splash screen).
- **paired**: Device has a valid token in `localStorage`, and either:
  - `/me` succeeded recently (device info fresh from server).
  - Network error (device info from cache, assume still paired, waiting for connectivity).
- **unpaired**: No token in `localStorage`, or `/me` returned 401 (token revoked/expired). Redirect to `/pairing`.

## Boot Verification (`GET /me`)

On `AuthProvider` mount:

1. Read `localStorage.kds_device_token` + `localStorage.kds_device_info`.
2. If absent → state = `unpaired` → exit loading state, redirect to `/pairing`.
3. If present → call `GET /api/v1/kds/me` with `Authorization: Bearer <token>` (silent, no toast on 401):
   - **200 OK** → parse response, update `kds_device_info`, state = `paired`.
   - **401 Unauthorized** → clear token + info from localStorage, state = `unpaired` → redirect to `/pairing`.
   - **Network error** (timeout, no connection) → keep state = `paired`, use cached device info (offline-tolerant).

Silent 401 means: don't show an error toast immediately on boot. But DO transition the UI.

## Global 401 Handler

`apiFetch()` utility (`src/lib/api.ts`) registers a callback:

```typescript
setUnauthorizedHandler(() => {
  // Called whenever any API returns 401
  localStorage.removeItem("kds_device_token");
  localStorage.removeItem("kds_device_info");
  authContext.setState("unpaired");
  navigate("/pairing");
});
```

This fires on:
- `/me` heartbeat returning 401 (boot or periodic check).
- `/orders` list returning 401.
- `/orders/:id/status` (bump mutation) returning 401.
- Any other protected endpoint.

Result: token revoked server-side (e.g., admin unpairs device) → client detects immediately on next API call → clears token → redirects to pairing.

## Token Storage

**localStorage keys:**
- `kds_device_token` — Bearer token (JWT or opaque string). Sent as `Authorization: Bearer <token>`.
- `kds_device_info` — JSON string: `{ id, name, type, branch_id, branch_name, shop_id, created_at, last_seen_at }`.

**Cleared on:**
- Logout (explicit "unpair" button) → `localStorage.clear()` or selective remove.
- 401 from any API (token revoked server-side).
- Boot transition to `unpaired`.

**No expiry tracking client-side** — server-side revoke is authoritative. Token may have an expiry (e.g., 30 days), but client doesn't decode/check it. When it expires server-side, next API call returns 401, handler clears it.

## Why Bearer Token + localStorage?

**Not HttpOnly Cookies:**
- Backend uses Bearer token pattern (consistent with kiosk, tms, workstation).
- HttpOnly cookies require:
  - Backend auth refactor (move from Authorization header to cookie parsing).
  - CORS preflight for every cross-origin request (added latency).
  - Complexity in workstation/kiosk that also use tokens.
- Decision: Keep Bearer tokens, accept localStorage XSS risk.

**XSS Risk Mitigation:**
- KDS is kitchen device, renders no untrusted content.
- No user-generated text, no CMS fields, no webhooks.
- Strict CSP in `index.html` (no inline scripts, no eval).
- Token scoped to device operations only (orders, bumps, branch data) — no user admin access.

## Network Mode + Base URL Resolution

After pairing, `/me`, `/orders`, `/orders/:id/status` calls route through `resolveBaseUrl()` (`src/services/base-url-resolver.ts`):

1. Try workstation LAN (mDNS `_workstation._tcp.local`, primary).
2. If unreachable after 30s of backoff → auto-fallback to cloud direct (`VITE_CLOUD_API_URL`).
3. Manual override: settings menu → "Use cloud" toggle.

**Pairing always targets cloud** (not workstation, token doesn't exist yet).

Once paired, all reads + mutations go through resolver:
- **LAN success** → stay on LAN (low latency, offline-tolerant).
- **LAN timeout** → fallback to cloud (seamless UX, no toast).
- **Cloud success** → stay on cloud (if admin toggled it or auto-fallback active).

Example:
```typescript
const baseUrl = await resolveBaseUrl(); // returns "http://workstation.local:8080" or "https://cloud.api.com"
const response = await apiFetch(`${baseUrl}/api/v1/kds/orders`, {
  headers: { Authorization: `Bearer ${token}` }
});
```

## Heartbeat (Phase 5+)

Once realtime WebSocket is implemented, `AuthProvider` may poll `/me` periodically (e.g., every 5 minutes) to detect revocation. Until then, detection happens on user action (tap order, bump item).

## See Also

- `src/app/auth-provider.tsx` — AuthProvider implementation (state machine, storage reads, /me call).
- `src/lib/api.ts` — `apiFetch()` helper, 401 handler registration.
- `src/services/auth/pairing.ts` — Pairing form, code exchange logic.
- `src/services/base-url-resolver.ts` — LAN vs. cloud resolution.
- umbrella `plans/plan-027/DESIGN.md` § Auth + Pairing — full spec.
