# [02] Workstation Integration — Demo (Option X full local replica)

> **Issues:** [godx-tempo#286](https://github.com/godx-jp/godx-tempo/issues/286) (pos-web) + [workstation-app#17](https://github.com/godx-jp/godx-tempo-workstation-app/issues/17) (workstation)
> **Scope:** Đưa pos-web vào 3-tier architecture (Cloud ↔ Workstation ↔ POS) với full local replica trên workstation.
> **Estimated:** 3-4 ngày dev (workstation 2d + pos-web 1.5-2d)
> **Status:** Draft — chưa start
> **Created:** 2026-05-23

**Goal:** Pos-web gọi workstation endpoints (LAN-first), workstation đọc/ghi local SQLite + sync với Cloud. Cùng pattern với kiosk integration đã làm.

**Non-goals (defer sprint sau):**
- Production HTTPS strategy (mixed-content cert handling)
- Cloud-assisted discovery (workstation IP heartbeat)
- Multi-workstation per shop
- Multi-terminal optimistic locking
- Offline IndexedDB queue trong browser
- WebSocket cross-terminal real-time

---

## Architecture (3-tier — confirmed)

```
                Cloud (HQ — multi-branch source of truth)
                       ↑ sync UP queue: POST /workstation/orders, /payments
                       ↓ sync DOWN pull: GET /workstation/menu, /tms/tables, /tms/zones
              Workstation (per restaurant)
                       ├── own SQLite: orders, order_items, payments, menu_items, tables, zones
                       ├── own HTTP endpoints :8080
                       │       ├── /api/v1/pos/*    ← NEW
                       │       ├── /api/v1/kiosk/*  (đã có)
                       │       ├── /api/v1/tms/*    (đã có)
                       │       └── /api/lan/*       (đã có)
                       └── auth_token_cache (SHA256 hash, 5min TTL)
                       ↑↓ HTTP LAN
             ┌─────────┴─────────┐
            POS (web)         Kiosk (native)
            ─ Vite dev :5440    ─ Expo native app
            ─ SSO Sanctum token ─ Device token
            ─ apiFetch          ─ apiFetch via mDNS discovery
              dual-mode           (LAN/Cloud fallback)
              (LAN/Cloud)
```

---

## File structure

### Workstation (Go)

| File | Status | Trách nhiệm |
|------|--------|-------------|
| `internal/service/cloud_verifier.go` | **MODIFY** | Detect token format → route đến `/me/context` (SSO) hoặc `/kiosk/me` (device) |
| `internal/service/auth_cache.go` | **MODIFY** | `Identity` struct: thêm `UserID/UserName/UserEmail/Type` |
| `internal/store/migrations/008_pos_auth_cache.sql` | **NEW** | Schema: add user_* columns to auth_token_cache |
| `internal/handler/local_pos.go` | **NEW** | 8 POS endpoints (me, menus, tables, orders CRUD, payments) |
| `internal/handler/local_pos_test.go` | **NEW** | Handler tests |
| `internal/handler/cors.go` | **NEW** | CORS middleware cho browser origin |
| `internal/handler/routes.go` | **MODIFY** | Register `/api/v1/pos/*` routes + CORS middleware |

### Pos-web (React)

| File | Status | Trách nhiệm |
|------|--------|-------------|
| `src/services/workstation/base-url-resolver.ts` | **NEW** | 3-mode resolver (auto/workstation/cloud) |
| `src/services/workstation/health.ts` | **NEW** | Health check workstation periodically |
| `src/lib/api.ts` | **MODIFY** | apiFetch use resolver, fallback logic |
| `src/services/pos-service.ts` | **NEW** | POS-specific endpoints (`/api/v1/pos/*`) |
| `src/services/order-service.ts` | **MODIFY** | Route qua POS endpoints (nếu mode != cloud) |
| `src/providers/workstation-provider.tsx` | **NEW** | Connection state + status broadcast |
| `src/components/connection-status-badge.tsx` | **NEW** | Top bar badge |
| `src/app/settings/connection-section.tsx` | **NEW** | Settings → mode toggle |
| `src/__tests__/integration/workstation-fallback.test.ts` | **NEW** | E2E MSW test |

---

## Phase 1 — Workstation cloud_verifier SSO support (~4-6h)

**Files:** `internal/service/cloud_verifier.go`, `auth_cache.go`, migration 008

### Step 1.1 — Schema migration

`internal/store/migrations/008_pos_auth_cache.sql`:

```sql
ALTER TABLE auth_token_cache ADD COLUMN user_id TEXT;
ALTER TABLE auth_token_cache ADD COLUMN user_name TEXT;
ALTER TABLE auth_token_cache ADD COLUMN user_email TEXT;
ALTER TABLE auth_token_cache ADD COLUMN identity_type TEXT NOT NULL DEFAULT 'device';
```

### Step 1.2 — Token format detection

```go
// internal/service/cloud_verifier.go
func detectTokenType(token string) string {
    // Sanctum personal access tokens: "{id}|{40-char-hash}"
    if strings.Contains(token, "|") {
        return "sso"
    }
    return "device"
}
```

### Step 1.3 — Route to correct endpoint

```go
func (v *CloudVerifier) Verify(ctx context.Context, token string) (*Identity, error) {
    var endpoint string
    switch detectTokenType(token) {
    case "sso":
        endpoint = "/api/v1/me/context"
    case "device":
        endpoint = "/api/v1/kiosk/me"
    }
    // call endpoint, parse response into Identity
}
```

### Step 1.4 — Identity struct refactor

```go
type Identity struct {
    Type           string // "user" | "device"
    DeviceID       string // empty if user
    UserID         string // empty if device
    Name           string
    Email          string // user only
    BranchID       string
    OrganizationID string
}
```

Parse khác nhau cho 2 endpoint response shapes:
- `/me/context` → `{ user: {id, name, email, locale}, brand_count, shop_count }`
- `/kiosk/me` → `{ data: {id, type, status, branch_id, organization_id} }`

### Step 1.5 — Tests

Unit test với 2 mock fetch responses → verify Identity populated đúng.

### Step 1.6 — Verify

```bash
cd workstation
go test -race ./internal/service/...
```

---

## Phase 2 — Workstation local_pos.go (~1 ngày)

**Files:** `internal/handler/local_pos.go`, `local_pos_test.go`, `cors.go`, `routes.go`

### Step 2.1 — CORS middleware

`internal/handler/cors.go`:

```go
func corsForBrowser(next http.Handler) http.Handler {
    return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
        origin := r.Header.Get("Origin")
        if origin != "" {
            w.Header().Set("Access-Control-Allow-Origin", origin)
            w.Header().Set("Vary", "Origin")
        }
        w.Header().Set("Access-Control-Allow-Methods", "GET, POST, PUT, PATCH, DELETE, OPTIONS")
        w.Header().Set("Access-Control-Allow-Headers", "Authorization, Content-Type, Idempotency-Key, Accept-Language")
        w.Header().Set("Access-Control-Allow-Credentials", "true")
        w.Header().Set("Access-Control-Max-Age", "600")

        if r.Method == http.MethodOptions {
            w.WriteHeader(http.StatusNoContent)
            return
        }
        next.ServeHTTP(w, r)
    })
}
```

### Step 2.2 — POS endpoints (8 total)

`internal/handler/local_pos.go` — mirror `local_kiosk.go` pattern:

| Endpoint | Implementation |
|----------|----------------|
| `GET /api/v1/pos/me` | Return Identity từ context (set bởi AuthMiddleware) |
| `GET /api/v1/pos/menus` | `SELECT * FROM menu_items WHERE is_active = 1` |
| `GET /api/v1/pos/tables` | `SELECT * FROM tables ORDER BY zone_id, sort_order` |
| `POST /api/v1/pos/orders` | Validate body → INSERT orders + order_items → enqueue `sync_queue` → return order |
| `GET /api/v1/pos/orders` | `SELECT * FROM orders WHERE status IN ('open','dining','checkout','paying')` |
| `GET /api/v1/pos/orders/{id}` | Return order + items |
| `POST /api/v1/pos/orders/{id}/items` | INSERT items + UPDATE totals + enqueue UP |
| `POST /api/v1/pos/orders/{id}/checkout` | UPDATE status → 'checkout' + enqueue UP |
| `POST /api/v1/pos/payments` | Idempotency-Key check → INSERT payment + UPDATE order → enqueue UP |

Reuse existing service functions: `service.OrderEngine`, sync queue Enqueue.

### Step 2.3 — Routes registration

`internal/handler/routes.go`:

```go
func (s *Server) registerPOSRoutes() {
    pos := s.mux.PathPrefix("/api/v1/pos").Subrouter()
    pos.Use(corsForBrowser)
    pos.Use(s.authMiddleware.Handler) // accepts both SSO + device tokens

    pos.HandleFunc("/me", s.handleLocalPosMe).Methods("GET", "OPTIONS")
    pos.HandleFunc("/menus", s.handleLocalPosMenus).Methods("GET", "OPTIONS")
    pos.HandleFunc("/tables", s.handleLocalPosTables).Methods("GET", "OPTIONS")
    pos.HandleFunc("/orders", s.handleLocalPosOrdersList).Methods("GET", "OPTIONS")
    pos.HandleFunc("/orders", s.handleLocalPosCreateOrder).Methods("POST", "OPTIONS")
    pos.HandleFunc("/orders/{id}", s.handleLocalPosGetOrder).Methods("GET", "OPTIONS")
    pos.HandleFunc("/orders/{id}/items", s.handleLocalPosAddItems).Methods("POST", "OPTIONS")
    pos.HandleFunc("/orders/{id}/checkout", s.handleLocalPosCheckout).Methods("POST", "OPTIONS")
    pos.HandleFunc("/payments", s.handleLocalPosCreatePayment).Methods("POST", "OPTIONS")
}
```

### Step 2.4 — Tests

Handler tests cho 8 endpoints với in-memory SQLite.

### Step 2.5 — Verify

```bash
# Manual smoke test
curl -X GET http://localhost:8080/api/v1/pos/menus \
    -H "Authorization: Bearer <sso-token>"

# CORS preflight
curl -X OPTIONS http://localhost:8080/api/v1/pos/orders \
    -H "Origin: http://localhost:5440" \
    -H "Access-Control-Request-Method: POST" -i
```

---

## Phase 3 — Pos-web dual-mode apiFetch (~1 ngày)

**Files:** `src/services/workstation/base-url-resolver.ts`, `src/lib/api.ts`, related

### Step 3.1 — Resolver

`src/services/workstation/base-url-resolver.ts`:

```typescript
export const CLOUD_URL = import.meta.env.VITE_API_URL || "http://localhost:5400";
export const WORKSTATION_URL = import.meta.env.VITE_WORKSTATION_API_URL || "http://localhost:8080";

export type ApiMode = "auto" | "workstation" | "cloud";
const STORAGE_MODE = "pos_api_mode";
const UNREACHABLE_MS = 30_000;

let unreachableUntil = 0;

export function getMode(): ApiMode {
  const v = localStorage.getItem(STORAGE_MODE);
  return (v === "workstation" || v === "cloud") ? v : "auto";
}

export function setMode(mode: ApiMode): void {
  localStorage.setItem(STORAGE_MODE, mode);
  unreachableUntil = 0; // reset on manual mode change
}

export function resolveBaseUrl(): { url: string; via: "workstation" | "cloud" } {
  const mode = getMode();
  if (mode === "cloud") return { url: CLOUD_URL, via: "cloud" };
  if (mode === "workstation") return { url: WORKSTATION_URL, via: "workstation" };
  // auto: try workstation unless recently unreachable
  if (Date.now() < unreachableUntil) return { url: CLOUD_URL, via: "cloud" };
  return { url: WORKSTATION_URL, via: "workstation" };
}

export function markWorkstationUnreachable(): void {
  unreachableUntil = Date.now() + UNREACHABLE_MS;
}

export function resetUnreachable(): void {
  unreachableUntil = 0;
}
```

### Step 3.2 — apiFetch refactor

`src/lib/api.ts`: thay base URL hardcoded bằng resolver. Preserve 401 handler + retry logic đã có.

```typescript
export async function apiFetch<T>(path: string, options?: RequestInit & { timeout?: number }): Promise<T> {
  const { url: baseUrl, via } = resolveBaseUrl();
  const usingWorkstation = via === "workstation";
  const timeout = options?.timeout ?? (usingWorkstation ? 3_000 : 15_000);

  try {
    return await doFetch<T>(baseUrl, path, options, timeout);
  } catch (err) {
    if (usingWorkstation && isNetworkError(err) && getMode() === "auto") {
      markWorkstationUnreachable();
      return await doFetch<T>(CLOUD_URL, path, options, 15_000);
    }
    throw err;
  }
}
```

### Step 3.3 — POS service endpoint mapping

`src/services/pos-service.ts` mới hoặc update `order-service.ts`:

```typescript
// When mode != cloud, call /api/v1/pos/*
// When mode == cloud, fallback /api/v1/shops/{slug}/*
```

Cụ thể: service layer detect mode → choose endpoint. Hoặc đơn giản hơn: workstation expose **cả** `/api/v1/pos/*` AND proxy `/api/v1/shops/*`, service luôn dùng `/shops/{slug}/*` paths.

→ Em chọn approach thứ 2 (workstation transparent proxy cho `/shops/{slug}/*`): không cần update service layer pos-web. Phase 2 sẽ implement proxy logic cho `/shops/{slug}/*` ở workstation.

→ **Update Phase 2 Step 2.3**: thêm proxy route `/api/v1/shops/{slug}/*` → forward Cloud, hoặc nếu là endpoints workstation đã có (orders, payments, menu) thì serve local.

### Step 3.4 — Network change listener

```typescript
window.addEventListener("online", () => resetUnreachable());
```

### Step 3.5 — Tests

Unit + integration với MSW (verified pattern từ Phase 1 hardening).

---

## Phase 4 — Settings UI + status badge (~0.5 ngày)

**Files:** `src/providers/workstation-provider.tsx`, `src/components/connection-status-badge.tsx`, `src/app/settings/connection-section.tsx`

### Step 4.1 — WorkstationProvider

State machine: track mode + workstation reachability + last health check timestamp.

Health check: every 30s khi mode == auto, gọi `GET {WORKSTATION_URL}/api/lan/health` (existing endpoint).

### Step 4.2 — Status badge

Top bar component:
- 🟢 "LAN" — using workstation, healthy
- 🟡 "Cloud (auto)" — fallback do workstation down
- 🔵 "Cloud" — manual mode
- 🔴 "Disconnected" — cả 2 unreachable

### Step 4.3 — Settings section

`src/app/settings/connection-section.tsx`:
- Section title: "Kết nối"
- 3 radio buttons: Auto / Workstation / Cloud
- Status display + last health check
- Workstation URL hiện tại
- Nút "Kiểm tra kết nối" → call `/api/lan/health`

### Step 4.4 — Tests

Component test renders correct status per mode + reachability state.

---

## Demo flow (định nghĩa "done")

1. Backend Cloud running (`docker compose up -d` umbrella)
2. Workstation paired với 1 shop, sync DOWN menu/tables/zones chạy
3. `cd workstation && make dev` → http://localhost:8080
4. `cd web/pos && pnpm dev` → http://localhost:5440
5. Mở browser `http://localhost:5440`
6. SSO login → workstation verify SSO token via Cloud `/me/context`
7. Settings → Status badge: 🟢 "LAN"
8. Tạo order với 3 items → POST `/api/v1/pos/orders` → workstation INSERT local + enqueue UP
9. Trong vài giây, Cloud nhận order (check admin-web)
10. Tạo payment cash → POST `/api/v1/pos/payments` với Idempotency-Key → workstation INSERT + sync UP
11. Settings → "Cloud direct" mode → POS gọi thẳng Cloud (bypass workstation)
12. Kill workstation → tạo order mới → vẫn work qua Cloud
13. Settings → "Auto" → restart workstation → badge về 🟢 "LAN"

---

## Risks & Mitigations

| Risk | Mitigation |
|------|-----------|
| SSO token detection fail-safe | Default device behavior, log warning, manual fallback |
| CORS preflight blocked | Test với curl trước, verify `Access-Control-*` headers đúng |
| Workstation timeout 3s quá ngắn | Tunable via env var |
| Race condition unreachable flag | Single mutable ref OK với JS single-thread |
| 401 token expire khi mid-mutation | Existing 401 handler ([#280](https://github.com/godx-jp/godx-tempo/issues/280)) handle |
| Sync UP queue đầy | Workstation đã có max_attempts + manual retry endpoint |

---

## Out of scope (Phase 2+)

- Production HTTPS (mixed-content, cert provisioning)
- Cloud-assisted workstation discovery
- Multi-workstation per shop failover
- WebSocket cross-terminal real-time sync
- Offline IndexedDB queue in browser
- Pos-web refactor ([#283](https://github.com/godx-jp/godx-tempo/issues/283))

---

## Status

- **Created:** 2026-05-23
- **Shipped:** 2026-05-23
- **Status:** COMPLETE ✅ — all 4 phases landed, both issues closed
- **Owner:** Claude Code
- **Reviewer:** @phamduyanh1910
- **Linked issues:**
  - ✅ [godx-tempo#286](https://github.com/godx-jp/godx-tempo/issues/286) — pos-web (closed)
  - ✅ [workstation-app#17](https://github.com/godx-jp/godx-tempo-workstation-app/issues/17) — workstation (closed)

### Final test counts

| Repo | Tests | Outcome |
|------|-------|---------|
| pos-web | 93 (Phase 1: 72 + new: 21) | All pass |
| workstation-app | All packages | `go test -race` xanh |

### Demo command

```bash
cd backend && docker compose up -d              # cloud
cd workstation && make dev                      # workstation :8080 (paired)
cd web/pos && pnpm dev                          # pos-web :5440
# Browser http://localhost:5440 → SSO login → header shows 🟢 LAN badge
```
